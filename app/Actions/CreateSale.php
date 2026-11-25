<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Support\BatchExpiry;
use App\Support\PriceTiers;
use App\Support\Imei;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSale
{
    public static function handle(
        array $lines,
        float $invoiceDiscount,
        ?int $customerId,
        ?int $userId,
        string $saleDate,
        float $amountPaid,
        ?int $shopId = null,
        array $attributes = [],
        ?string $paymentMethod = null,
        ?int $shopAccountId = null,
        array $installments = [],
        ?array $tradeIn = null,
        float $storeCredit = 0.0,
        bool $applyPromotions = false,
        ?array $prescription = null,
    ): Sale {
        return DB::transaction(function () use ($lines, $invoiceDiscount, $customerId, $userId, $saleDate, $amountPaid, $shopId, $attributes, $paymentMethod, $shopAccountId, $installments, $tradeIn, $storeCredit, $applyPromotions, $prescription) {
            $subtotal = 0;
            $allItemsData = [];
            $redemptions = [];
            // Only the register asks; WhatsApp and online orders sell at their own prices.
            $running = null;

            foreach ($lines as $line) {
                $productQuery = Product::whereKey($line['product_id']);

                if ($shopId !== null) {
                    $productQuery->where('shop_id', $shopId);
                }

                $product = $productQuery->lockForUpdate()->firstOrFail();
                $product->unit->assertQuantity($line['quantity'], "lines.{$product->id}", $product->name);
                // A trade scheme's free pieces (W9) go out on the same line, priced at nothing.
                $free = ! empty($line['scheme_id']) ? Quantity::normalize($line['scheme_free'] ?? 0) : 0;
                $quantity = Quantity::add(Quantity::normalize($line['quantity']), $free);

                if (Quantity::compare($product->stock_quantity, $quantity) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$product->id}" => 'Only '.$product->stockLabel()." left in stock for {$product->name}.",
                    ]);
                }

                $allocations = ConsumeStockFifo::handle($product, $quantity);
                BatchExpiry::assertNotDrawingExpired($product, $allocations, "lines.{$product->id}");
                if ($applyPromotions) {
                    $running ??= Promotions::runningOn($shopId ?? $product->shop_id, Carbon::parse($saleDate)->toDateString());
                }

                // A wholesale customer's trade price (PriceTiers) is the line's price unless the
                // cashier typed one; it is also the list price, so it never reads as overridden.
                $tierPrice = isset($line['tier_price']) ? (float) $line['tier_price'] : null;
                $subLines = $free > 0
                    ? Promotions::priceLineWithFree($product, $line['custom_price'] ?? $tierPrice, (float) $line['discount'], $allocations, $free)
                    : Promotions::priceLine($product, $line['custom_price'] ?? $tierPrice, (float) $line['discount'], $allocations, $running);

                if ($free > 0) {
                    $lineCost = (float) array_sum(array_map(fn (array $a) => Quantity::money($a['cost_price'] ?? 0, $a['quantity']), $allocations));
                    $redemptions[] = [(int) $line['scheme_id'], $product->id, $free, (float) $quantity > 0 ? $lineCost * (float) $free / (float) $quantity : 0.0];
                }
                $tier = $tierPrice !== null ? ['list_price' => $tierPrice, 'price_tier' => $line['price_tier'] ?? PriceTiers::TRADE] : [];

                // The pack it was sold in (strip, box…), when the line was whole packs.
                $pack = SaleItem::packAttributes($line['pack_name'] ?? null, $line['pack_size'] ?? null, $quantity);

                foreach ($subLines as $subLine) {
                    $subtotal += $subLine['line_total'];
                    $allItemsData[] = array_merge($subLine, [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_type' => $product->type,
                        'unit' => $product->unit,
                    ], $pack, SaleItem::warrantyAttributesFor($product), $tier);
                }

                $product->decrement('stock_quantity', $quantity);
            }

            $subtotal = round($subtotal, 2);
            $invoiceDiscount = min($invoiceDiscount, $subtotal);

            $sale = new Sale(array_merge($attributes, [
                'user_id' => $userId,
                'customer_id' => $customerId,
                'subtotal' => $subtotal,
                'discount_amount' => $invoiceDiscount,
                'total' => $subtotal - $invoiceDiscount,
            ]));

            if ($shopId !== null) {
                $sale->shop_id = $shopId;
            }

            $sale->created_at = Carbon::parse($saleDate)->setTimeFrom(now());
            $sale->save();

            foreach ($allItemsData as $itemData) {
                $sale->items()->create(array_merge($itemData, ['shop_id' => $sale->shop_id]));
            }

            foreach ($redemptions as [$schemeId, $productId, $free, $costValue]) {
                Schemes::redeem($sale, $schemeId, $productId, $free, $costValue);
            }

            // Prescription medicines go in the register: with the register's details, or
            // (a sale made elsewhere) left to fill in from the sale page.
            Prescriptions::syncForSale($sale, $prescription, $userId, $prescription !== null ? 'register' : ($sale->source?->value ?? 'register'));

            $sale->payments()->create(array_merge([
                'shop_id' => $sale->shop_id,
                'user_id' => $userId,
                'amount' => $amountPaid,
                'payment_date' => now()->toDateString(),
            ], SalePaymentMethod::paymentAttributes($paymentMethod, $shopAccountId, $sale->shop_id, $amountPaid)));

            $tradeInValue = $tradeIn !== null ? self::addTradeIn($sale, $tradeIn, $amountPaid, $userId, $saleDate) : 0.0;
            $creditValue = round($storeCredit, 2) > 0 ? self::addStoreCredit($sale, round($storeCredit, 2), $amountPaid + $tradeInValue, $userId) : 0.0;

            if ($installments !== []) {
                self::createInstallments($sale, $installments, $amountPaid + $tradeInValue + $creditValue);
            }

            return $sale;
        });
    }

    /**
     * The customer's used phone as part of the payment: a buyback paid by
     * "trade-in" (no money moves, see Buybacks) and a Trade-in payment row of
     * the same value on this sale. Returns the value.
     *
     * @param  array<string, mixed>  $tradeIn  Buybacks::rules() fields; seller_name defaults to the customer's name
     */
    protected static function addTradeIn(Sale $sale, array $tradeIn, float $amountPaid, ?int $userId, string $saleDate): float
    {
        $value = round((float) ($tradeIn['price'] ?? 0), 2);

        if ($value <= 0) {
            throw ValidationException::withMessages(['tradeIn.price' => 'Enter what the traded-in phone is worth.']);
        }

        if ($value + $amountPaid > (float) $sale->total + 0.009) {
            throw ValidationException::withMessages(['tradeIn.price' => 'The trade-in (Rs '.number_format($value, 2).') and the amount paid can\'t add up to more than the sale (Rs '.number_format((float) $sale->total, 2).'). For a phone worth more, record it on Used Phone Buyback and pay the difference.']);
        }

        $imei = Imei::normalize((string) ($tradeIn['imei'] ?? ''));

        if ($imei !== '' && $sale->items->contains(fn (SaleItem $item) => $item->product_id !== null && Imei::normalize((string) (Product::withoutGlobalScopes()->find($item->product_id)?->details['imei'] ?? '')) === $imei)) {
            throw ValidationException::withMessages(['tradeIn.imei' => 'This IMEI is on a phone in this sale. A phone can\'t be sold and traded in at once.']);
        }

        $shop = Shop::findOrFail($sale->shop_id);
        $sellerName = trim((string) ($tradeIn['seller_name'] ?? '')) ?: (string) Customer::withoutGlobalScopes()->whereKey($sale->customer_id)->value('name');

        try {
            $buyback = Buybacks::record($shop, array_merge($tradeIn, [
                'seller_name' => $sellerName !== '' ? $sellerName : 'Walk-in customer',
                'price' => $value,
                'bought_on' => Carbon::parse($saleDate)->toDateString(),
            ]), $userId, null, null, tradeInFor: $sale);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['tradeIn.'.$key => $messages])->all());
        }

        $payment = $sale->payments()->create([
            'shop_id' => $sale->shop_id,
            'user_id' => $userId,
            'amount' => $value,
            'payment_date' => now()->toDateString(),
            'payment_method' => SalePaymentMethod::TradeIn->value,
            'shop_account_id' => null,
        ]);

        $buyback->update(['sale_payment_id' => $payment->id]);

        return $value;
    }

    /**
     * @param  list<array{sequence: int, due_date: string, amount: float|string}>  $installments
     */
    /** Part of the sale paid from the customer's store credit: a payment that moves no money. */
    protected static function addStoreCredit(Sale $sale, float $amount, float $alreadyPaid, ?int $userId): float
    {
        if (round($amount + $alreadyPaid, 2) > round((float) $sale->total, 2)) {
            throw ValidationException::withMessages(['storeCredit' => 'Store credit and the amount paid can\'t add up to more than the sale (Rs '.number_format((float) $sale->total, 2).').']);
        }

        $payment = $sale->payments()->create([
            'shop_id' => $sale->shop_id,
            'user_id' => $userId,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => SalePaymentMethod::StoreCredit->value,
        ]);

        StoreCredit::spend($sale, $payment, $amount, $userId);

        return $amount;
    }

    protected static function createInstallments(Sale $sale, array $installments, float $amountPaid): void
    {
        if ($sale->customer_id === null) {
            throw ValidationException::withMessages(['customerId' => 'Choose the customer who is paying in instalments.']);
        }

        $remaining = round((float) $sale->total - $amountPaid, 2);
        $scheduled = round(array_sum(array_map(fn ($row) => (float) $row['amount'], $installments)), 2);

        if ($remaining <= 0 || abs($scheduled - $remaining) > 0.009) {
            throw ValidationException::withMessages(['installmentCount' => 'The instalments (Rs '.number_format($scheduled, 2).') must add up to what is left after the down payment (Rs '.number_format(max(0, $remaining), 2).').']);
        }

        $saleDay = $sale->created_at->toDateString();

        foreach (array_values($installments) as $i => $row) {
            if ((float) $row['amount'] <= 0 || Carbon::parse($row['due_date'])->toDateString() < $saleDay) {
                throw ValidationException::withMessages(['installmentCount' => 'Each instalment needs an amount and a due date on or after the sale date.']);
            }

            $sale->installments()->create([
                'shop_id' => $sale->shop_id,
                'sequence' => $i + 1,
                'due_date' => Carbon::parse($row['due_date'])->toDateString(),
                'amount' => round((float) $row['amount'], 2),
            ]);
        }
    }
}
