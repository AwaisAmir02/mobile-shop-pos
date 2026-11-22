<?php

namespace App\Actions;

use App\Enums\BuybackCondition;
use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Brand;
use App\Models\Buyback;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockIn;
use App\Support\Imei;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Used-phone buyback for Mobile & Electronics shops (Shop::sellsDevices()).
 *
 * Buying a phone from a customer is a purchase like any other: a Mobile
 * product (brand, model, IMEI, condition in details) and one stock-in batch
 * of 1 with no vendor, fully paid from cash or an account. The batch carries
 * cost, FIFO and profit; its payment row is the money out that Cash &
 * Balances reads. The Buyback row keeps the seller and the phone's state.
 *
 * A phone the shop sold before keeps its IMEI on that sold product (one
 * product per IMEI), so buying it back restocks that product instead of
 * making a second one; cancelling puts the product back as it was.
 *
 * Cancelling reverses the batch through CancelStockIn, so a phone already
 * sold on can't be cancelled here (cancel the sale first). The batch can't
 * be edited or cancelled from Stock In while its buyback stands.
 */
class Buybacks
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'seller_name' => ['required', 'string', 'max:255'],
            'seller_phone' => ['nullable', 'string', 'max:30'],
            'seller_cnic' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && self::cnic((string) $value) === null) {
                    $fail('A CNIC is 13 digits, e.g. 35202-1234567-1.');
                }
            }],
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'imei' => ['nullable', 'string', 'max:30'],
            'condition' => ['required', Rule::enum(BuybackCondition::class)],
            'battery_health' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pta_approved' => ['nullable', 'boolean'],
            'has_box' => ['boolean'],
            'has_charger' => ['boolean'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'bought_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** "3520212345671" or "35202-1234567-1" as 35202-1234567-1; null if it isn't 13 digits. */
    public static function cnic(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 13 ? substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12) : null;
    }

    /**
     * @param  array{seller_name: string, seller_phone?: ?string, seller_cnic?: ?string, brand: string, model?: ?string, imei?: ?string, condition: string, battery_health?: ?int, pta_approved?: ?bool, has_box?: bool, has_charger?: bool, price: float|string, selling_price: float|string, bought_on: string, note?: ?string}  $data
     */
    public static function record(Shop $shop, array $data, ?int $userId, ?string $paymentMethod, ?int $shopAccountId, ?Sale $tradeInFor = null): Buyback
    {
        if (! $shop->sellsDevices()) {
            throw ValidationException::withMessages(['brand' => 'Used phone buyback is for Mobile & Electronics shops.']);
        }

        // A trade-in is paid by the sale it part-pays, never from the drawer; only CreateSale makes one.
        if ($tradeInFor !== null) {
            $paymentMethod = SalePaymentMethod::TradeIn->value;
            $shopAccountId = null;
        } elseif ($paymentMethod === SalePaymentMethod::TradeIn->value) {
            throw ValidationException::withMessages(['brand' => 'A trade-in is recorded from the New Sale screen.']);
        }

        $imei = Imei::normalize($data['imei'] ?? '');

        if ($imei !== '' && ($problem = Imei::problem($imei)) !== null) {
            throw ValidationException::withMessages(['imei' => $problem]);
        }

        return DB::transaction(function () use ($shop, $data, $userId, $paymentMethod, $shopAccountId, $imei, $tradeInFor) {
            MainCategory::ensureDefaultsExist($shop->id);

            $brand = trim($data['brand']);
            $model = trim((string) ($data['model'] ?? '')) ?: null;
            $condition = BuybackCondition::from($data['condition']);
            $price = round((float) $data['price'], 2);
            $sellingPrice = round((float) $data['selling_price'], 2);

            // Kept in the Brands list so the product stays editable on the Products screen.
            Brand::withoutGlobalScopes()->firstOrCreate(['shop_id' => $shop->id, 'name' => $brand]);

            $details = [
                'brand' => $brand,
                'model' => $model,
                'imei' => $imei !== '' ? $imei : null,
                'category' => null,
                'condition' => $condition->value,
            ];
            $attributes = [
                'name' => trim($brand.' '.$model).' (Used)',
                'price' => $sellingPrice,
                'cost_price' => $price,
                'archived_at' => null,
            ];

            $existing = $imei !== '' ? Imei::owner($shop->id, $imei) : null;
            $before = null;

            if ($existing !== null) {
                $existing = Product::withoutGlobalScopes()->whereKey($existing->id)->lockForUpdate()->firstOrFail();

                if ((float) $existing->stock_quantity > 0) {
                    throw ValidationException::withMessages(['imei' => 'This IMEI is already in stock as '.Imei::describeOwner($existing).'. A phone can only be bought in once while it is still in stock.']);
                }

                $before = $existing->only(['name', 'price', 'cost_price', 'archived_at', 'details']);
                $before['archived_at'] = $existing->archived_at?->toIso8601String();
                $existing->update($attributes + ['details' => array_merge($existing->details ?? [], $details)]);
                $product = $existing;
            } else {
                $product = Product::create($attributes + [
                    'shop_id' => $shop->id,
                    'type' => 'mobile',
                    'stock_quantity' => 0,
                    'details' => $details,
                ]);
            }

            $phone = trim((string) ($data['seller_phone'] ?? '')) ?: null;
            $customerId = $tradeInFor?->customer_id ?? ($phone !== null
                ? Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->where('phone', $phone)->value('id')
                : null);

            $batch = CreateStockIn::handle(
                [['product_id' => $product->id, 'quantity' => 1, 'cost_price' => $price, 'selling_price' => $sellingPrice]],
                null,
                'paid',
                $price,
                $data['bought_on'],
                ($tradeInFor ? 'Phone traded in on '.$tradeInFor->invoiceNumber().' by ' : 'Used phone bought from ').trim($data['seller_name']),
                $userId,
                null,
                $paymentMethod,
                $shopAccountId,
            )->first();

            return Buyback::create([
                'shop_id' => $shop->id,
                'branch_id' => $batch->branch_id,
                'user_id' => $userId,
                'customer_id' => $customerId,
                'product_id' => $product->id,
                'stock_in_id' => $batch->id,
                'sale_id' => $tradeInFor?->id,
                'seller_name' => trim($data['seller_name']),
                'seller_phone' => $phone,
                'seller_cnic' => filled($data['seller_cnic'] ?? null) ? self::cnic($data['seller_cnic']) : null,
                'brand' => $brand,
                'model' => $model,
                'imei' => $imei !== '' ? $imei : null,
                'condition' => $condition,
                'battery_health' => $data['battery_health'] ?? null,
                'pta_approved' => $data['pta_approved'] ?? null,
                'has_box' => (bool) ($data['has_box'] ?? false),
                'has_charger' => (bool) ($data['has_charger'] ?? false),
                'price' => $price,
                'selling_price' => $sellingPrice,
                'bought_on' => $data['bought_on'],
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'reused_product' => $before !== null,
                'product_before' => $before,
            ]);
        });
    }

    /**
     * A trade-in is part of its sale's payment, so on its own it can't be
     * cancelled: cancelling the sale does it ($fromSale), handing the phone back.
     */
    public static function cancel(Buyback $buyback, ?int $userId, ?string $reason = null, bool $fromSale = false): Buyback
    {
        return DB::transaction(function () use ($buyback, $userId, $reason, $fromSale) {
            $buyback = Buyback::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($buyback->id)->lockForUpdate()->firstOrFail();

            if ($buyback->isCancelled()) {
                throw ValidationException::withMessages(['buyback' => 'This buyback has already been cancelled.']);
            }

            if ($buyback->sale_id !== null && ! $fromSale) {
                $number = Sale::withoutGlobalScopes()->find($buyback->sale_id)?->invoiceNumber() ?? 'its sale';

                throw ValidationException::withMessages(['buyback' => 'This phone was traded in on '.$number.'. Cancel that sale instead; that hands the phone back.']);
            }

            $batch = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->stock_in_id);

            if ((float) $batch->quantity_remaining < (float) $batch->quantity) {
                throw ValidationException::withMessages(['buyback' => 'This phone has already been sold. Cancel that sale first, then cancel the buyback.']);
            }

            CancelStockIn::handle($batch, $userId, $reason ?: 'Buyback cancelled', fromBuyback: true);

            $product = Product::withoutGlobalScopes()->whereKey($buyback->product_id)->lockForUpdate()->first();

            if ($product !== null) {
                if ($buyback->reused_product && is_array($buyback->product_before)) {
                    $product->update($buyback->product_before);
                } else {
                    // Kept (it holds the IMEI and the cancelled batch), just out of the catalogue.
                    $product->update(['archived_at' => now()]);
                }
            }

            $buyback->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            return $buyback->fresh();
        });
    }

    public static function forStockIn(int $stockInId): bool
    {
        return Buyback::withoutGlobalScopes()->where('stock_in_id', $stockInId)->where('status', RecordStatus::Active->value)->exists();
    }
}
