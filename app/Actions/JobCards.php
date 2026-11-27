<?php

namespace App\Actions;

use App\Enums\JobCardStatus;
use App\Enums\JobItemKind;
use App\Enums\JobLineKind;
use App\Models\Employee;
use App\Models\JobCard;
use App\Models\JobCardLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceItem;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\PhoneNumber;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Job cards for a repair workshop (AC, bike, auto, appliances). A job holds
 * the units brought in (with the details their kind asks for), the parts and
 * labour priced on it, an estimate the customer approves, and where it
 * stands. Parts on an open job are held from other channels
 * (StockAvailability, StockHoldKind::JobCard) but only leave stock when the
 * job is billed, through the register (?job_card=ID) and CreateSale, which is
 * also what makes it Delivered and starts its warranty.
 *
 * The mobile shop's Repairs module is a different, older record and is not
 * touched here.
 */
class JobCards
{
    /**
     * @param  array{customer_id: int, technician_id?: ?int, received_on?: ?string, promised_on?: ?string, site_visit?: bool, site_address?: ?string, visit_charge?: mixed, warranty_days?: mixed, notes?: ?string}  $fields
     * @param  list<array{kind: string, label: string, details?: array, problem?: ?string}>  $items
     */
    public static function open(array $fields, array $items, int $shopId, ?int $userId): JobCard
    {
        return DB::transaction(function () use ($fields, $items, $shopId, $userId) {
            $job = new JobCard(self::cardAttributes($fields, $shopId) + ['user_id' => $userId, 'received_on' => $fields['received_on'] ?? now('Asia/Karachi')->toDateString()]);
            $job->shop_id = $shopId;
            $job->save();
            self::saveItems($job, $items);

            return $job->load('items');
        });
    }

    public static function update(JobCard $job, array $fields, array $items): JobCard
    {
        return DB::transaction(function () use ($job, $fields, $items) {
            $job = self::lockOpen($job);
            $job->update(self::cardAttributes($fields, $job->shop_id));
            self::saveItems($job, $items);

            return $job->fresh(['items', 'lines']);
        });
    }

    /**
     * Replaces the priced lines. A part names a product; labour a menu service
     * or just a description. Prices are what the customer will pay.
     *
     * @param  list<array{kind: string, product_id?: ?int, service_item_id?: ?int, description?: ?string, quantity?: mixed, unit_price?: mixed, employee_id?: ?int, job_card_item_id?: ?int}>  $lines
     */
    public static function saveLines(JobCard $job, array $lines): JobCard
    {
        return DB::transaction(function () use ($job, $lines) {
            $job = self::lockOpen($job);
            $rows = [];

            foreach (array_values($lines) as $i => $line) {
                $kind = JobLineKind::tryFrom((string) ($line['kind'] ?? ''));
                $key = "lines.{$i}";

                if ($kind === null) {
                    throw ValidationException::withMessages([$key => 'Each line is a part or labour.']);
                }

                $price = round((float) ($line['unit_price'] ?? 0), 2);
                $quantity = Quantity::normalize($line['quantity'] ?? 1);

                if ($price < 0 || Quantity::compare($quantity, 0) <= 0) {
                    throw ValidationException::withMessages([$key => 'Give each line a quantity above zero and a price.']);
                }

                if (! empty($line['employee_id']) && ! Employee::withoutGlobalScopes()->where('shop_id', $job->shop_id)->whereKey((int) $line['employee_id'])->exists()) {
                    throw ValidationException::withMessages([$key => 'Choose one of your staff.']);
                }

                if ($kind === JobLineKind::Part) {
                    $product = Product::withoutGlobalScopes()->where('shop_id', $job->shop_id)->find((int) ($line['product_id'] ?? 0));

                    if ($product === null) {
                        throw ValidationException::withMessages([$key => 'Choose the part from your products.']);
                    }

                    $product->unit->assertQuantity($quantity, $key, $product->name);
                    $description = $product->name;
                    $serviceId = null;
                } else {
                    $service = ! empty($line['service_item_id']) ? ServiceItem::withoutGlobalScopes()->where('shop_id', $job->shop_id)->find((int) $line['service_item_id']) : null;
                    $description = trim((string) ($line['description'] ?? '')) !== '' ? trim((string) $line['description']) : $service?->name;

                    if ($description === null || $description === '') {
                        throw ValidationException::withMessages([$key => 'Say what the labour was.']);
                    }

                    $product = null;
                    $serviceId = $service?->id;
                    $quantity = max(1, (int) $quantity);
                }

                $rows[] = [
                    'shop_id' => $job->shop_id,
                    'job_card_item_id' => ! empty($line['job_card_item_id']) && $job->items->contains('id', (int) $line['job_card_item_id']) ? (int) $line['job_card_item_id'] : null,
                    'kind' => $kind,
                    'product_id' => $product?->id,
                    'service_item_id' => $serviceId,
                    'description' => mb_substr($description, 0, 255),
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'employee_id' => ! empty($line['employee_id']) ? (int) $line['employee_id'] : null,
                ];
            }

            $job->lines()->delete();

            foreach ($rows as $row) {
                $job->lines()->create($row);
            }

            // A changed price list needs the customer's OK again.
            $job->load('lines');

            if ($job->estimate_approved_at !== null && round((float) $job->estimate_total, 2) !== $job->total()) {
                $job->update(['estimate_approved_at' => null, 'estimate_approved_via' => null]);
            }

            $job->update(['estimate_total' => $job->total()]);

            return $job->fresh(['items', 'lines']);
        });
    }

    /** The customer agreed to the estimate as it stands (in person, by phone, on WhatsApp). */
    public static function approveEstimate(JobCard $job, string $via): JobCard
    {
        $job = self::lockOpen($job);
        $job->load('lines');
        $job->update(['estimate_total' => $job->total(), 'estimate_approved_at' => now(), 'estimate_approved_via' => mb_substr($via, 0, 30)]);

        if ($job->status === JobCardStatus::AwaitingApproval) {
            $job->update(['status' => JobCardStatus::InProgress]);
        }

        return $job;
    }

    /**
     * Moves an open job along. Ready tells the customer: on WhatsApp through
     * the shop's number when it has Customer Messages, otherwise the screen
     * offers a link to send from the shop's own phone.
     *
     * @return array{job: JobCard, notified: ?string} notified: null, 'sent', or why it wasn't
     */
    public static function move(JobCard $job, JobCardStatus $status, ?int $userId): array
    {
        if (! in_array($status, JobCardStatus::settable(), true)) {
            throw ValidationException::withMessages(['status' => 'A job is delivered by billing it, and cancelled with its reason.']);
        }

        $job = DB::transaction(function () use ($job, $status) {
            $job = self::lockOpen($job);
            $job->update(['status' => $status] + ($status === JobCardStatus::Ready ? ['ready_at' => now()] : []));

            return $job;
        });

        $notified = null;

        if ($status === JobCardStatus::Ready) {
            try {
                app(CustomerNotifications::class)->jobCardReady($job, $userId);
                $notified = 'sent';
            } catch (Throwable $e) {
                $notified = $e->getMessage();
            }
        }

        return ['job' => $job, 'notified' => $notified];
    }

    public static function cancel(JobCard $job, ?int $userId, string $reason): JobCard
    {
        return DB::transaction(function () use ($job, $userId, $reason) {
            $job = self::lockOpen($job);

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['cancelReason' => 'Say why the job is cancelled.']);
            }

            $job->update(['status' => JobCardStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => mb_substr(trim($reason), 0, 255)]);

            return $job;
        });
    }

    /** The register billed it: delivered, and its warranty starts today. */
    public static function billed(JobCard $job, Sale $sale): void
    {
        $job = self::lockOpen($job);
        $today = now('Asia/Karachi');

        $job->update([
            'status' => JobCardStatus::Delivered,
            'sale_id' => $sale->id,
            'delivered_at' => now(),
            'warranty_until' => $job->warranty_days ? $today->copy()->addDays($job->warranty_days)->toDateString() : null,
            'service_due_on' => $job->next_service_days ? $today->copy()->addDays($job->next_service_days)->toDateString() : null,
            'service_reminded_at' => null,
        ]);
    }

    /** Its bill was cancelled: back to Ready, to bill again. */
    public static function billCancelled(Sale $sale): void
    {
        JobCard::withoutGlobalScopes()->where('sale_id', $sale->id)->update(['status' => JobCardStatus::Ready->value, 'sale_id' => null, 'delivered_at' => null, 'warranty_until' => null, 'service_due_on' => null]);
    }

    /**
     * What the register puts on the bill: parts as product lines (they leave
     * stock then), labour and the visit charge as service lines.
     *
     * @return array{products: list<array{product_id: int, quantity: int|float, unit_price: float}>, services: list<array<string, mixed>>}
     */
    public static function billLines(JobCard $job): array
    {
        $job->loadMissing('lines');
        $products = [];
        $services = [];

        foreach ($job->lines as $line) {
            if ($line->kind === JobLineKind::Part) {
                $products[] = ['product_id' => (int) $line->product_id, 'quantity' => $line->quantity, 'unit_price' => (float) $line->unit_price];

                continue;
            }

            $services[] = ['kind' => 'service', 'service_item_id' => $line->service_item_id, 'name' => $line->description, 'unit_price' => (string) (float) $line->unit_price, 'quantity' => (int) $line->quantity, 'discount' => '0', 'performed_by' => (string) ($line->employee_id ?? $job->technician_id ?? '')];
        }

        if ((float) $job->visit_charge > 0) {
            $services[] = ['kind' => 'service', 'service_item_id' => null, 'name' => 'Site visit', 'unit_price' => (string) (float) $job->visit_charge, 'quantity' => 1, 'discount' => '0', 'performed_by' => (string) ($job->technician_id ?? '')];
        }

        return ['products' => $products, 'services' => $services];
    }

    /** The "it's ready" text to send from the shop's own phone when WhatsApp isn't connected. */
    public static function readyText(JobCard $job, string $shopName): string
    {
        $job->loadMissing(['items', 'lines']);

        return 'Assalam o Alaikum '.$job->customer?->name.', your '.$job->unitsLabel().' ('.$job->number().') at '.$shopName.' is ready.'
            .($job->total() > 0 ? ' The bill is Rs '.number_format($job->total(), 0).'.' : '').' Thank you!';
    }

    /** wa.me link with a message, or null for a number that can't take one. */
    public static function whatsappLink(?string $phone, string $text): ?string
    {
        $number = PhoneNumber::normalize((string) $phone);

        return $number === null ? null : 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }

    protected static function lockOpen(JobCard $job): JobCard
    {
        $job = JobCard::withoutGlobalScopes()->with('items')->whereKey($job->id)->lockForUpdate()->firstOrFail();

        if (! $job->status->isOpen()) {
            throw ValidationException::withMessages(['job' => $job->number().' is already '.strtolower($job->status->label()).'.']);
        }

        return $job;
    }

    protected static function cardAttributes(array $fields, int $shopId): array
    {
        if (! empty($fields['technician_id']) && ! Employee::withoutGlobalScopes()->where('shop_id', $shopId)->whereKey((int) $fields['technician_id'])->exists()) {
            throw ValidationException::withMessages(['technicianId' => 'Choose one of your staff.']);
        }

        $siteVisit = (bool) ($fields['site_visit'] ?? false);

        if ($siteVisit && trim((string) ($fields['site_address'] ?? '')) === '') {
            throw ValidationException::withMessages(['siteAddress' => 'Give the address for the site visit.']);
        }

        return [
            'customer_id' => (int) $fields['customer_id'],
            'technician_id' => ! empty($fields['technician_id']) ? (int) $fields['technician_id'] : null,
            'promised_on' => ! empty($fields['promised_on']) ? Carbon::parse($fields['promised_on'])->toDateString() : null,
            'site_visit' => $siteVisit,
            'site_address' => $siteVisit ? mb_substr(trim((string) $fields['site_address']), 0, 300) : null,
            'visit_charge' => $siteVisit ? round(max(0.0, (float) ($fields['visit_charge'] ?? 0)), 2) : 0,
            'warranty_days' => isset($fields['warranty_days']) && $fields['warranty_days'] !== '' && $fields['warranty_days'] !== null ? max(0, (int) $fields['warranty_days']) : null,
            // The unit's next service (a bike every 90 days): a reminder goes out when it's due.
            'next_service_days' => isset($fields['next_service_days']) && $fields['next_service_days'] !== '' && $fields['next_service_days'] !== null ? max(1, (int) $fields['next_service_days']) : null,
            'notes' => isset($fields['notes']) && trim((string) $fields['notes']) !== '' ? mb_substr(trim((string) $fields['notes']), 0, 1000) : null,
        ];
    }

    /** @param list<array{id?: ?int, kind: string, label: string, details?: array, problem?: ?string, findings?: ?string}> $items */
    protected static function saveItems(JobCard $job, array $items): void
    {
        $kept = [];

        foreach (array_values($items) as $i => $item) {
            $kind = JobItemKind::tryFrom((string) ($item['kind'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));

            if ($kind === null || $label === '') {
                throw ValidationException::withMessages(["items.{$i}.label" => 'Name each unit brought in (e.g. Honda CD70, Haier 1.5 ton).']);
            }

            $details = collect($kind->fields())->mapWithKeys(fn ($l, $key) => [$key => mb_substr(trim((string) ($item['details'][$key] ?? '')), 0, 100)])->filter(fn ($v) => $v !== '')->all();
            $attributes = ['kind' => $kind, 'label' => mb_substr($label, 0, 150), 'details' => $details ?: null, 'problem' => self::text($item['problem'] ?? null), 'findings' => self::text($item['findings'] ?? null)];
            $existing = ! empty($item['id']) ? $job->items->firstWhere('id', (int) $item['id']) : null;

            if ($existing !== null) {
                $existing->update($attributes);
                $kept[] = $existing->id;
            } else {
                $kept[] = $job->items()->create($attributes + ['shop_id' => $job->shop_id])->id;
            }
        }

        if ($kept === []) {
            throw ValidationException::withMessages(['items' => 'Add the unit that was brought in.']);
        }

        $job->items()->whereNotIn('id', $kept)->delete();
    }

    protected static function text(mixed $value): ?string
    {
        return $value !== null && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 1000) : null;
    }
}
