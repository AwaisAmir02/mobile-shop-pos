<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StockIn;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * What an expiry date means for selling. A batch can be sold through its
 * expiry day and is expired from the next day, by the shop's own calendar:
 * the app runs in UTC, which is still yesterday until 5am in Karachi.
 */
final class BatchExpiry
{
    public const SOON_DAYS = 30;

    public static function today(): string
    {
        return CarbonImmutable::now(Entitlements::TIMEZONE)->toDateString();
    }

    public static function soonCutoff(int $days = self::SOON_DAYS): string
    {
        return CarbonImmutable::now(Entitlements::TIMEZONE)->addDays($days)->toDateString();
    }

    /**
     * Refuses a sale that drew from an expired batch. Runs inside the sale's
     * transaction, so refusing also undoes the draw. Expired batches come
     * last in the sell order, so this only triggers once everything else has
     * run out. $allowance is what an edited sale already held in each batch:
     * that stock left the shop before it expired, so it may stay on the sale.
     *
     * @param  array<int, array{stock_in_id: ?int, quantity: int|float}>  $allocations
     * @param  array<int, int|float>  $allowance  stock_in_id => quantity
     */
    public static function assertNotDrawingExpired(Product $product, array $allocations, string $errorKey, array $allowance = []): void
    {
        if (! $product->sellsByExpiry()) {
            return;
        }

        $expired = self::expiredBatchIds($allocations);
        $over = 0;

        foreach ($allocations as $allocation) {
            if (in_array($allocation['stock_in_id'], $expired, true)) {
                $over += max(0, Quantity::toThousandths($allocation['quantity']) - Quantity::toThousandths($allowance[$allocation['stock_in_id']] ?? 0));
            }
        }

        if ($over > 0) {
            throw ValidationException::withMessages([
                $errorKey => $product->unit->amount(Quantity::fromThousandths($over))." of {$product->name} is expired. Update Stock In or add fresh stock.",
            ]);
        }
    }

    /**
     * For a sale screen: how much of this line would come from expired stock,
     * and the soonest date among what it would draw, if that's coming up.
     *
     * @param  array<int, array{stock_in_id: ?int, quantity: int|float}>  $allocations
     * @return array{expired: int|float, soonest: ?CarbonImmutable}|null null when there's nothing to say
     */
    public static function noteFor(Product $product, array $allocations): ?array
    {
        if (! $product->sellsByExpiry()) {
            return null;
        }

        $ids = array_values(array_filter(array_column($allocations, 'stock_in_id')));
        $dates = $ids === [] ? collect() : StockIn::withoutGlobalScopes()->whereIn('id', $ids)->whereNotNull('expiry_date')->get(['id', 'expiry_date'])->pluck('expiry_date', 'id');
        $today = self::today();
        $expired = 0;
        $soonest = null;

        foreach ($allocations as $allocation) {
            $date = $dates->get($allocation['stock_in_id'])?->toDateString();

            if ($date === null) {
                continue;
            }

            if ($date < $today) {
                $expired += Quantity::toThousandths($allocation['quantity']);
            } elseif ($date <= self::soonCutoff() && ($soonest === null || $date < $soonest)) {
                $soonest = $date;
            }
        }

        if ($expired === 0 && $soonest === null) {
            return null;
        }

        return ['expired' => Quantity::fromThousandths($expired), 'soonest' => $soonest !== null ? CarbonImmutable::parse($soonest) : null];
    }

    /** @return array<int, int> */
    protected static function expiredBatchIds(array $allocations): array
    {
        $ids = array_values(array_filter(array_column($allocations, 'stock_in_id')));

        return $ids === [] ? [] : StockIn::withoutGlobalScopes()->whereIn('id', $ids)->where('expiry_date', '<', self::today())->pluck('id')->all();
    }
}
