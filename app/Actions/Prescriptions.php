<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\SalePrescription;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The prescription / controlled-drug register.
 *
 * Every sale holding a medicine the shop marked "Prescription only" or
 * "Controlled" gets one register entry per such medicine, numbered in one
 * series per shop. The entries of a sale share its details: the patient,
 * the prescriber (with registration number and prescription date) and a
 * photo of the prescription. No prescriber means an emergency sale, which
 * needs a reason. A controlled drug also needs the patient's name.
 *
 * The register asks for the details at the sale (CreateSale's prescription).
 * A sale made elsewhere (WhatsApp, online) gets its entries with the details
 * left blank, to fill in from the sale page. An edit keeps the details and
 * follows the new quantities.
 */
class Prescriptions
{
    public const DISK = 'local';

    public const PHOTO_MAX_KB = 5120;

    /** @return array<string, string> blank register details, as the screens hold them */
    public static function blank(): array
    {
        return ['patient_name' => '', 'prescriber_name' => '', 'prescriber_reg_no' => '', 'prescribed_on' => '', 'emergency_reason' => ''];
    }

    /**
     * Writes or updates the sale's register entries to match its items.
     *
     * @param  array<string, mixed>|null  $details  blank() keys plus an optional 'photo' upload; null keeps what the entries have
     */
    public static function syncForSale(Sale $sale, ?array $details, ?int $userId, string $source = 'register'): void
    {
        $existing = SalePrescription::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
        $inRegister = $existing->pluck('control', 'product_id');

        // Marked medicines, and any already in this sale's register even if unmarked since.
        $flagged = DB::table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.sale_id', $sale->id)
            ->where(fn ($query) => $query->whereNotNull('products.rx_control')->orWhereIn('sale_items.product_id', $inRegister->keys()->all()))
            ->groupBy('sale_items.product_id', 'products.rx_control', 'products.name')
            ->get(['sale_items.product_id', 'products.rx_control', 'products.name', DB::raw('SUM(sale_items.quantity) as quantity')])
            ->each(fn ($row) => $row->rx_control ??= $inRegister->get($row->product_id))
            ->keyBy('product_id');

        // A medicine an edit took off the sale keeps its entry at nothing given, so a
        // register number is never removed or reused.
        foreach ($existing->whereNotIn('product_id', $flagged->keys()->all()) as $gone) {
            $gone->update(['quantity' => 0]);
        }

        if ($flagged->isEmpty()) {
            return;
        }

        $attributes = $details !== null
            ? self::detailAttributes($details, $flagged->contains('rx_control', SalePrescription::CONTROLLED), $sale->shop_id)
            : self::sharedDetails($existing->first());

        $byProduct = $existing->keyBy('product_id');

        foreach ($flagged as $productId => $row) {
            $values = ['quantity' => Quantity::normalize($row->quantity), 'control' => $row->rx_control];

            if (($entry = $byProduct->get($productId)) !== null) {
                $entry->update($values + ($details !== null ? $attributes : []));

                continue;
            }

            SalePrescription::withoutGlobalScopes()->create($values + $attributes + [
                'shop_id' => $sale->shop_id,
                'sale_id' => $sale->id,
                'product_id' => $productId,
                'product_name' => $row->name,
                'serial_number' => self::nextSerial($sale->shop_id),
                'dispensed_on' => $sale->created_at->toDateString(),
                'source' => $source,
                'recorded_by' => $userId,
            ]);
        }
    }

    /**
     * Fills in or corrects a sale's register details from the sale page. A
     * new photo replaces the old one; none keeps it.
     *
     * @param  array<string, mixed>  $details
     */
    public static function updateDetails(Sale $sale, array $details): void
    {
        DB::transaction(function () use ($sale, $details) {
            $entries = SalePrescription::withoutGlobalScopes()->where('sale_id', $sale->id)->lockForUpdate()->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['prescription' => 'This sale has no prescription medicines.']);
            }

            $attributes = self::detailAttributes($details, $entries->contains('control', SalePrescription::CONTROLLED), $sale->shop_id);
            $oldPhoto = $entries->first()->photo_path;

            if (! array_key_exists('photo_path', $attributes)) {
                $attributes['photo_path'] = $oldPhoto;
            }

            foreach ($entries as $entry) {
                $entry->update($attributes);
            }

            if ($oldPhoto !== null && $oldPhoto !== $attributes['photo_path']) {
                self::forgetPhotoIfUnused($oldPhoto);
            }
        });
    }

    /**
     * Checks the details and turns them into entry columns, storing a photo
     * if one came. Errors are keyed prescription.*.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function detailAttributes(array $details, bool $controlled, int $shopId): array
    {
        $details = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $details + self::blank());

        $validator = Validator::make($details, [
            'patient_name' => [$controlled ? 'required' : 'nullable', 'string', 'max:120'],
            'prescriber_name' => ['nullable', 'string', 'max:120'],
            'prescriber_reg_no' => ['nullable', 'string', 'max:40'],
            'prescribed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'emergency_reason' => [$details['prescriber_name'] === '' ? 'required' : 'nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::PHOTO_MAX_KB],
        ], [
            'patient_name.required' => 'A controlled drug needs the patient\'s name.',
            'emergency_reason.required' => 'No prescriber: this is recorded as an emergency sale. Write why it was given without a prescription.',
        ], [
            'prescriber_name' => 'prescriber', 'prescriber_reg_no' => 'registration number', 'prescribed_on' => 'prescription date', 'emergency_reason' => 'reason', 'photo' => 'prescription photo',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $key) => ['prescription.'.$key => $messages])->all());
        }

        $emergency = $details['prescriber_name'] === '';
        $attributes = [
            'patient_name' => $details['patient_name'] !== '' ? $details['patient_name'] : null,
            'prescriber_name' => $emergency ? null : $details['prescriber_name'],
            'prescriber_reg_no' => $emergency || $details['prescriber_reg_no'] === '' ? null : $details['prescriber_reg_no'],
            'prescribed_on' => ! $emergency && $details['prescribed_on'] !== '' ? Carbon::parse($details['prescribed_on'])->toDateString() : null,
            'is_emergency' => $emergency,
            'emergency_reason' => $emergency ? $details['emergency_reason'] : null,
        ];

        if (($details['photo'] ?? null) instanceof UploadedFile) {
            $attributes['photo_path'] = $details['photo']->store('prescriptions/'.$shopId, self::DISK);
        }

        return $attributes;
    }

    /** @return array<string, mixed> the details a new entry copies from the sale's other entries */
    protected static function sharedDetails(?SalePrescription $entry): array
    {
        return $entry === null ? [] : $entry->only(['patient_name', 'prescriber_name', 'prescriber_reg_no', 'prescribed_on', 'is_emergency', 'emergency_reason', 'photo_path']);
    }

    protected static function nextSerial(int $shopId): int
    {
        return (int) SalePrescription::withoutGlobalScopes()->where('shop_id', $shopId)->lockForUpdate()->max('serial_number') + 1;
    }

    protected static function forgetPhotoIfUnused(?string $path): void
    {
        if ($path !== null && ! SalePrescription::withoutGlobalScopes()->where('photo_path', $path)->exists()) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
