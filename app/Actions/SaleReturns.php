<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Enums\SalePaymentMethod;
use App\Enums\WriteOffReason;
use App\Ledger\LedgerObserver;
use App\Models\Buyback;
use App\Models\Customer;
use App\Models\StoreCreditEntry;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StockWriteOff;
use App\Services\Billing\Entitlements;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns against an earlier sale.
 *
 * A sale's lines are already split by batch (one sale_items row per batch a
 * product was drawn from), so a return takes back from those rows and each
 * unit goes back to the batch it left, at the cost it left with. A product
 * line returned in part is taken from its newest row first, like an undo.
 *
 * A row's value is what was charged for it: its line total less its share of
 * any discount on the whole bill (shares split in paisa so they add up to that
 * discount exactly). Returning the last of a row takes exactly what's left of
 * its value, so returning a whole sale gives back exactly its total.
 *
 * What the goods are worth first cancels what the customer still owes on the
 * sale; only the rest is handed back. The sale's own total and payments never
 * change: the return is its own document, counted on the day it happens.
 */
class SaleReturns
{
    public const MAX_LATE_REASON = 255;

    /** Where the part that isn't owed goes: money, store credit, or money where the shop gives it and credit where it doesn't. */
    public const REFUND_CASH = 'cash';

    public const REFUND_CREDIT = 'credit';

    public const REFUND_AUTO = 'auto';

    /**
     * One entry per product line of the sale, with what's left to return.
     *
     * @return array<string, array{key: string, product_id: ?int, name: string, unit: \App\Enums\QuantityUnit, serial: ?string, sold: int|float, returned: int|float, returnable: int|float, value: float, returned_value: float, unit_value: float, rows: Collection<int, SaleItem>, imei: bool}>
     */
    public static function groups(Sale $sale): array
    {
        $items = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->orderBy('id')->get();
        $values = self::rowValues($sale, $items);
        $groups = [];

        foreach ($items as $item) {
            $key = $item->product_id !== null ? 'p'.$item->product_id : 'n'.substr(md5($item->product_name.'|'.$item->unit_price), 0, 12);
            $returned = $item->returnedQuantity();
            $returnedValue = $item->returnedValue();

            $groups[$key] ??= [
                'key' => $key,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'unit' => $item->unit,
                'serial' => $item->serial_number,
                'sold' => 0,
                'returned' => 0,
                'returnable' => 0,
                'value' => 0.0,
                'returned_value' => 0.0,
                'rows' => collect(),
                'imei' => $item->serial_number !== null,
                'pack' => $item->pack_name !== null && (int) $item->pack_size > 1 ? ['name' => $item->pack_name, 'size' => (int) $item->pack_size] : null,
            ];

            $group = &$groups[$key];
            $group['sold'] = Quantity::add($group['sold'], $item->quantity);
            $group['returned'] = Quantity::add($group['returned'], $returned);
            $group['returnable'] = Quantity::add($group['returnable'], Quantity::subtract($item->quantity, $returned));
            $group['value'] = round($group['value'] + $values[$item->id], 2);
            $group['returned_value'] = round($group['returned_value'] + $returnedValue, 2);
            $group['rows']->push($item);
            unset($group);
        }

        foreach ($groups as &$group) {
            $group['unit_value'] = Quantity::compare($group['sold'], 0) > 0
                ? round($group['value'] * 1000 / Quantity::toThousandths($group['sold']), 2)
                : 0.0;
        }

        return $groups;
    }

    /**
     * Each row's share of the bill: its line total less its part of the
     * discount on the whole bill, split by line total in paisa so the parts
     * add up to that discount exactly (largest remainder).
     *
     * @return array<int, float> sale item id => value
     */
    public static function rowValues(Sale $sale, Collection $items): array
    {
        $discount = (int) round((float) $sale->discount_amount * 100);
        $totals = $items->mapWithKeys(fn (SaleItem $item) => [$item->id => (int) round((float) $item->line_total * 100)]);
        $sum = $totals->sum();
        $shares = $totals->map(fn () => 0)->all();

        if ($discount > 0 && $sum > 0) {
            $remainders = [];
            $given = 0;

            foreach ($totals as $id => $paise) {
                $exact = $discount * $paise / $sum;
                $shares[$id] = (int) floor($exact);
                $remainders[$id] = $exact - floor($exact);
                $given += $shares[$id];
            }

            arsort($remainders);

            foreach (array_keys($remainders) as $id) {
                if ($given >= $discount) {
                    break;
                }

                $shares[$id]++;
                $given++;
            }
        }

        return $totals->map(fn (int $paise, int $id) => max(0, $paise - $shares[$id]) / 100)->all();
    }

    /** The day a sale was made, in the shop's own time zone. */
    public static function saleDate(Sale $sale): string
    {
        return CarbonImmutable::parse($sale->created_at)->setTimezone(Entitlements::TIMEZONE)->toDateString();
    }

    /** Days between the sale and a return on $date; null when the shop has no limit. */
    public static function daysLate(Sale $sale, Shop $shop, string $date): ?int
    {
        $window = (int) $shop->return_window_days;

        if ($window <= 0) {
            return null;
        }

        $days = (int) round(abs(CarbonImmutable::parse(self::saleDate($sale))->diffInDays(CarbonImmutable::parse($date))));

        return $days > $window ? $days : null;
    }

    /** Whether a traded-in phone paid for part of this sale: the shop keeps it on a return. */
    public static function hadTradeIn(Sale $sale): bool
    {
        return Buyback::withoutGlobalScopes()->where('sale_id', $sale->id)->where('status', RecordStatus::Active->value)->exists();
    }

    /**
     * @param  array<string, array{quantity: mixed, disposition?: string}>  $lines  by group key
     */
    public static function create(
        Sale $sale,
        array $lines,
        ?string $refundMethod,
        ?int $shopAccountId,
        ?string $reason,
        ?int $userId,
        ?string $returnDate = null,
        ?string $lateReason = null,
        bool $byOwner = false,
        string $refundTo = self::REFUND_AUTO,
        ?int $creditCustomerId = null,
        float $toExchange = 0.0,
        ?int $exchangeSaleId = null,
    ): SaleReturn {
        return DB::transaction(function () use ($sale, $lines, $refundMethod, $shopAccountId, $reason, $userId, $returnDate, $lateReason, $byOwner, $refundTo, $creditCustomerId, $toExchange, $exchangeSaleId) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw ValidationException::withMessages(['lines' => 'A cancelled sale can\'t have returns.']);
            }

            $shop = Shop::findOrFail($sale->shop_id);
            $date = $returnDate ?: BatchExpiry::today();

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > BatchExpiry::today() || $date < self::saleDate($sale)) {
                throw ValidationException::withMessages(['returnDate' => 'The return date has to be between the sale and today.']);
            }

            if (($late = self::daysLate($sale, $shop, $date)) !== null) {
                if (! $byOwner) {
                    throw ValidationException::withMessages(['lines' => "This shop takes returns within {$shop->return_window_days} days of a sale, and this one was {$late} days ago. Only the owner can take it back now."]);
                }

                if (blank($lateReason)) {
                    throw ValidationException::withMessages(['lateReason' => "This sale was {$late} days ago, past the {$shop->return_window_days}-day limit. Say why it's being taken back."]);
                }
            }

            $groups = self::groups($sale);
            $wanted = [];

            foreach ($lines as $key => $line) {
                $quantity = trim((string) ($line['quantity'] ?? ''));

                if ($quantity === '' || (is_numeric($quantity) && Quantity::compare($quantity, 0) === 0)) {
                    continue;
                }

                $group = $groups[$key] ?? throw ValidationException::withMessages(['lines' => 'That item isn\'t on this sale.']);
                $group['unit']->assertQuantity($quantity, "lines.{$key}.quantity", $group['name']);

                if (Quantity::compare($quantity, $group['returnable']) > 0) {
                    throw ValidationException::withMessages(["lines.{$key}.quantity" => Quantity::compare($group['returnable'], 0) > 0
                        ? 'Only '.$group['unit']->amount($group['returnable'])." of {$group['name']} is left to return."
                        : "All of {$group['name']} has already been returned."]);
                }

                $disposition = $line['disposition'] ?? SaleReturnItem::RESTOCK;

                if (! in_array($disposition, [SaleReturnItem::RESTOCK, SaleReturnItem::DAMAGED], true)) {
                    throw ValidationException::withMessages(["lines.{$key}.disposition" => 'Choose Back to stock or Damaged.']);
                }

                $wanted[$key] = ['quantity' => Quantity::normalize($quantity), 'disposition' => $disposition];
            }

            if ($wanted === []) {
                throw ValidationException::withMessages(['lines' => 'Enter how much of at least one item is coming back.']);
            }

            $dueBefore = $sale->amountDue();
            $values = self::rowValues($sale, collect($groups)->flatMap(fn (array $group) => $group['rows']));

            $return = SaleReturn::create([
                'shop_id' => $sale->shop_id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'user_id' => $userId,
                'return_date' => $date,
                'value_total' => 0,
                'reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null,
                'late_reason' => filled($lateReason) && self::daysLate($sale, $shop, $date) !== null ? mb_substr(trim($lateReason), 0, self::MAX_LATE_REASON) : null,
            ]);

            $total = 0.0;
            $touched = [];

            foreach (self::plan($wanted, $groups, $values) as ['row' => $row, 'quantity' => $quantity, 'value' => $value, 'disposition' => $disposition]) {
                $total = round($total + $value, 2);

                $item = $return->items()->create([
                    'shop_id' => $sale->shop_id,
                    'sale_item_id' => $row->id,
                    'product_id' => $row->product_id,
                    'stock_in_id' => $row->stock_in_id,
                    'product_name' => $row->product_name,
                    'serial_number' => $row->serial_number,
                    'quantity' => $quantity,
                    'unit' => $row->unit,
                    'cost_price' => $row->cost_price,
                    'value' => $value,
                    'disposition' => $disposition,
                ]);

                self::putBack($return, $item, $row, $quantity, $userId, $date);

                if ($item->restocked && $row->product_id !== null) {
                    $touched[$row->product_id] = $row->stock_in_id === null || ($touched[$row->product_id] ?? false);
                }
            }

            $dueReduction = round(min($total, $dueBefore), 2);
            $refund = round($total - $dueReduction, 2);
            $source = ['payment_method' => null, 'shop_account_id' => null];

            // In an exchange, what's due back pays for the new sale first; only the rest is refunded.
            $exchange = round(min($refund, max(0.0, $toExchange)), 2);
            $refund = round($refund - $exchange, 2);

            $toCredit = $refund > 0 && ($refundTo === self::REFUND_CREDIT || ($refundTo === self::REFUND_AUTO && ! $shop->cash_refunds_allowed));
            $creditTo = null;

            if ($toCredit) {
                // Store credit belongs to a customer: the sale's own, or one chosen for it.
                $creditTo = $sale->customer_id ?? $creditCustomerId;

                if ($creditTo === null || ! Customer::withoutGlobalScopes()->whereKey($creditTo)->where('shop_id', $sale->shop_id)->exists()) {
                    throw ValidationException::withMessages(['creditCustomerId' => 'Choose the customer to give Rs '.number_format($refund, 2).' of store credit to.']);
                }
            } elseif ($refund > 0) {
                if (! $shop->cash_refunds_allowed) {
                    throw ValidationException::withMessages(['refund' => 'This shop doesn\'t hand back money on returns, so the Rs '.number_format($refund, 2).' can only be given as store credit.']);
                }

                $source = SalePaymentMethod::paymentAttributes($refundMethod, $shopAccountId, $sale->shop_id, $refund);
            }

            $return->update([
                'customer_id' => $creditTo ?? $sale->customer_id,
                'value_total' => $total,
                'due_reduction' => $dueReduction,
                'refund_cash' => $toCredit ? 0 : $refund,
                'refund_credit' => $toCredit ? $refund : 0,
                'refund_exchange' => $exchange,
                'exchange_sale_id' => $exchangeSaleId,
                'refund_method' => $source['payment_method'],
                'shop_account_id' => $source['shop_account_id'],
            ]);

            if ($toCredit) {
                StoreCredit::issue($return->fresh(), $creditTo, $refund, $userId);
            }

            self::afterStockChange($touched);

            return $return->fresh('items');
        });
    }

    /**
     * Which rows each line takes from and what that's worth, without writing
     * anything: the screen's preview and the save use exactly this.
     *
     * @param  array<string, array{quantity: int|float, disposition: string}>  $wanted
     * @return array<int, array{row: SaleItem, quantity: int|float, value: float, disposition: string}>
     */
    public static function plan(array $wanted, array $groups, array $values): array
    {
        $plan = [];

        foreach ($wanted as $key => $line) {
            $left = Quantity::toThousandths($line['quantity']);

            // Newest row first: the last batch drawn is the first put back.
            foreach ($groups[$key]['rows']->sortByDesc('id') as $row) {
                if ($left <= 0) {
                    break;
                }

                $available = Quantity::toThousandths(Quantity::subtract($row->quantity, $row->returnedQuantity()));
                $take = min($left, $available);

                if ($take <= 0) {
                    continue;
                }

                $plan[] = ['row' => $row, 'quantity' => Quantity::fromThousandths($take), 'value' => self::valueOf($row, $values[$row->id], $take, $available), 'disposition' => $line['disposition']];
                $left -= $take;
            }
        }

        return $plan;
    }

    /**
     * What a return of these lines would be worth, for the screen. Lines that
     * aren't valid yet are left out rather than refused.
     *
     * @param  array<string, array{quantity: mixed, disposition?: string}>  $lines
     */
    public static function previewValue(Sale $sale, array $lines): float
    {
        $groups = self::groups($sale);
        $wanted = [];

        foreach ($lines as $key => $line) {
            $quantity = trim((string) ($line['quantity'] ?? ''));

            if (! isset($groups[$key]) || ! is_numeric($quantity) || Quantity::compare($quantity, 0) <= 0 || Quantity::compare($quantity, $groups[$key]['returnable']) > 0 || ! Quantity::fitsScale($quantity)) {
                continue;
            }

            $wanted[$key] = ['quantity' => Quantity::normalize($quantity), 'disposition' => SaleReturnItem::RESTOCK];
        }

        $values = self::rowValues($sale, collect($groups)->flatMap(fn (array $group) => $group['rows']));

        return round(array_sum(array_column(self::plan($wanted, $groups, $values), 'value')), 2);
    }

    /**
     * Cancels a return: the goods leave stock again (refused once they've been
     * sold on) and its refund and due reduction stop counting.
     */
    public static function cancel(SaleReturn $return, ?int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($return, $userId, $reason) {
            $return = SaleReturn::withoutGlobalScopes()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->isCancelled()) {
                throw ValidationException::withMessages(['cancelReason' => 'This return has already been cancelled.']);
            }

            // An exchange is undone as a whole: its new sale is cancelled with it
            // (refused if that sale can't be, e.g. it has a return of its own).
            if ($return->exchange_sale_id !== null) {
                $exchangeSale = Sale::withoutGlobalScopes()->find($return->exchange_sale_id);

                if ($exchangeSale !== null && ! $exchangeSale->isCancelled()) {
                    CancelSale::handle($exchangeSale, $userId, 'Exchange '.$return->number().' cancelled', fromExchange: true);
                }
            }

            // Its store credit first: refused once any of it has been used.
            foreach (StoreCreditEntry::withoutGlobalScopes()->where('sale_return_id', $return->id)->where('type', StoreCreditEntry::ISSUE)->where('status', RecordStatus::Active->value)->get() as $issue) {
                StoreCredit::cancel($issue, $userId, 'Return '.$return->number().' cancelled');
            }

            $items = $return->items()->get();
            $touched = [];

            foreach ($items as $item) {
                if (! $item->restocked || $item->product_id === null) {
                    continue;
                }

                if ($item->stock_write_off_id !== null) {
                    $writeOff = StockWriteOff::withoutGlobalScope(ExcludesCancelledScope::class)->find($item->stock_write_off_id);

                    if ($writeOff !== null && ! $writeOff->isCancelled()) {
                        CancelStockWriteOff::handle($writeOff, $userId, 'Return '.$return->number().' cancelled');
                    }
                }

                if ($item->stock_in_id !== null) {
                    $batch = StockIn::withoutGlobalScopes()->whereKey($item->stock_in_id)->lockForUpdate()->first();

                    if ($batch !== null) {
                        if (Quantity::compare($batch->quantity_remaining, $item->quantity) < 0) {
                            throw ValidationException::withMessages(['cancelReason' => "The {$item->product_name} taken back on this return has been sold again, so the return can't be cancelled."]);
                        }

                        $batch->decrement('quantity_remaining', Quantity::normalize($item->quantity));
                    }
                }

                $product = Product::withoutGlobalScopes()->whereKey($item->product_id)->lockForUpdate()->first();

                if ($product !== null) {
                    if (Quantity::compare($product->stock_quantity, $item->quantity) < 0) {
                        throw ValidationException::withMessages(['cancelReason' => "The {$item->product_name} taken back on this return has been sold again, so the return can't be cancelled."]);
                    }

                    $product->decrement('stock_quantity', Quantity::normalize($item->quantity));
                    $touched[$product->id] = $item->stock_in_id === null || ($touched[$product->id] ?? false);
                }
            }

            $snapshot = $return->only(['sale_id', 'customer_id', 'value_total', 'due_reduction', 'refund_cash', 'refund_credit', 'refund_exchange', 'shop_account_id', 'reason']) + [
                'return_date' => $return->return_date->toDateString(),
                'refund_method' => $return->refund_method?->value,
                'items' => $items->map(fn (SaleReturnItem $item) => $item->only(['sale_item_id', 'stock_in_id', 'product_name', 'quantity', 'value', 'cost_price', 'disposition', 'restocked', 'stock_write_off_id']))->all(),
            ];

            $return->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $return->shop_id,
                'revisionable_type' => SaleReturn::class,
                'revisionable_id' => $return->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            self::afterStockChange($touched);
        });
    }

    /** What a return of these lines would be due back: its value less what it takes off the amount owed. */
    public static function refundableFor(Sale $sale, array $lines): float
    {
        $value = self::previewValue($sale, $lines);

        return round($value - min($value, $sale->amountDue()), 2);
    }

    /** The active return an exchange sale was paid by, if it was one. */
    public static function exchangeFor(Sale $sale): ?SaleReturn
    {
        return SaleReturn::withoutGlobalScopes()->where('exchange_sale_id', $sale->id)->where('status', RecordStatus::Active->value)->first();
    }

    /** The active returns of a sale, newest first, as a short list for messages. */
    public static function numbersFor(Sale $sale): string
    {
        return SaleReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->where('status', RecordStatus::Active->value)
            ->orderBy('id')->get()->map->number()->implode(', ');
    }

    /** A sale with an active return can't be edited or cancelled until the return is. */
    public static function assertNoReturns(Sale $sale, string $errorKey, string $doing): void
    {
        $numbers = self::numbersFor($sale);

        if ($numbers !== '') {
            throw ValidationException::withMessages([$errorKey => "This sale has a return ({$numbers}), so it can't be {$doing}. Cancel the return first."]);
        }
    }

    /**
     * What $take thousandths of a row are worth. Taking all that's left of
     * the row takes exactly the rest of its value, so nothing is lost to
     * rounding over several returns.
     */
    protected static function valueOf(SaleItem $row, float $rowValue, int $take, int $available): float
    {
        $remaining = round($rowValue - $row->returnedValue(), 2);

        if ($take >= $available) {
            return max(0.0, $remaining);
        }

        $sold = Quantity::toThousandths($row->quantity);

        return $sold > 0 ? min($remaining, round($rowValue * $take / $sold, 2)) : 0.0;
    }

    /** Back on its batch; a damaged unit is then written off from that same batch. */
    protected static function putBack(SaleReturn $return, SaleReturnItem $item, SaleItem $row, int|float $quantity, ?int $userId, string $date): void
    {
        // Damaged old stock with no batch has nowhere exact to go back to: it stays sold.
        if ($item->disposition === SaleReturnItem::DAMAGED && $row->stock_in_id === null) {
            $item->update(['restocked' => false]);

            return;
        }

        if (! RestoreStockFifo::restoreFromItem($row, $quantity)) {
            $item->update(['restocked' => false]);

            return;
        }

        if ($item->disposition === SaleReturnItem::DAMAGED) {
            $writeOff = CreateStockWriteOff::handle(
                Product::findOrFail($row->product_id),
                $quantity,
                WriteOffReason::Damaged,
                'Returned damaged on '.$return->number(),
                $userId,
                $date,
                $row->stock_in_id,
            );

            $item->update(['stock_write_off_id' => $writeOff->id]);
        }
    }

    /**
     * Products whose stock changed: un-archived when they have stock again, and
     * (for stock no batch covers) noted for the ledger, which values that stock
     * separately and isn't told by an increment.
     *
     * @param  array<int, bool>  $touched  product id => whether unbatched stock moved
     */
    protected static function afterStockChange(array $touched): void
    {
        foreach ($touched as $productId => $unbatched) {
            $product = Product::withoutGlobalScopes()->find($productId);

            if ($product === null) {
                continue;
            }

            if ($product->archived_at !== null && $product->stock_quantity > 0) {
                $product->forceFill(['archived_at' => null])->saveQuietly();
            }

            if ($unbatched) {
                app(LedgerObserver::class)->saved($product);
            }
        }
    }
}
