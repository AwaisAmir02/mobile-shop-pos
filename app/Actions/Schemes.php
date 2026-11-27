<?php

namespace App\Actions;

use App\Enums\SaleStatus;
use App\Enums\SchemeClaimStatus;
use App\Enums\VendorCreditSource;
use App\Models\Sale;
use App\Models\Scheme;
use App\Models\SchemeClaim;
use App\Models\SchemeRedemption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trade schemes (W9): "buy 10, get 1 free" on a product, for a period.
 *
 * At the register (and on delivering an order) the free pieces go out on the
 * same line as the bought ones, priced at nothing: the line's quantity is the
 * bought pieces plus the free ones, and the free ones' value is taken off as
 * discount (Promotions::priceLineWithFree). Stock and cost follow the real
 * quantity, so profit is right. Each sale's free goods are recorded at the
 * cost they left stock at (SchemeRedemption); when a company (vendor) funds
 * the scheme they are claimed back from it at that cost, which is how
 * principals reimburse their distributors. A claim settles as credit against
 * what the shop owes them, or as money (both through VendorCredits).
 */
class Schemes
{
    /** The scheme running on a product on a day, if any (the newest wins). */
    public static function runningFor(int $productId, string $date): ?Scheme
    {
        return Scheme::query()->where('product_id', $productId)->where('is_active', true)
            ->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->latest('id')
            ->first();
    }

    /** Called by CreateSale for a line with free goods. */
    public static function redeem(Sale $sale, int $schemeId, int $productId, int|float $free, float $costValue): void
    {
        SchemeRedemption::create([
            'shop_id' => $sale->shop_id,
            'scheme_id' => $schemeId,
            'sale_id' => $sale->id,
            'product_id' => $productId,
            'free_quantity' => $free,
            'cost_value' => round($costValue, 2),
        ]);
    }

    /** Free goods given on live sales of schemes this vendor funds, not yet claimed. */
    public static function unclaimed(int $vendorId, ?string $from = null, ?string $to = null): Collection
    {
        return SchemeRedemption::query()
            ->whereNull('scheme_claim_id')
            ->whereHas('scheme', fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereHas('sale', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', SaleStatus::Cancelled->value)
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to)))
            ->with(['scheme', 'sale.customer'])
            ->orderBy('id')
            ->get();
    }

    /** A claim for everything unclaimed in the period. */
    public static function claim(int $vendorId, ?string $from, ?string $to, ?string $note, ?int $userId): SchemeClaim
    {
        return DB::transaction(function () use ($vendorId, $from, $to, $note, $userId) {
            $redemptions = self::unclaimed($vendorId, $from, $to);

            if ($redemptions->isEmpty()) {
                throw ValidationException::withMessages(['claim' => 'There are no free goods to claim from this company for those dates.']);
            }

            $claim = SchemeClaim::create([
                'vendor_id' => $vendorId,
                'claimed_on' => now()->toDateString(),
                'claimed_amount' => round((float) $redemptions->sum('cost_value'), 2),
                'status' => SchemeClaimStatus::Submitted,
                'note' => filled($note) ? $note : null,
                'user_id' => $userId,
            ]);

            SchemeRedemption::whereIn('id', $redemptions->pluck('id'))->update(['scheme_claim_id' => $claim->id]);

            return $claim;
        });
    }

    /**
     * The company paid: as credit against what the shop owes them, or in
     * money. Less than claimed is allowed (a short settlement); the rest is
     * written off with the claim.
     */
    public static function settle(SchemeClaim $claim, float $amount, string $settlement, ?string $method, ?int $shopAccountId, ?int $userId): void
    {
        DB::transaction(function () use ($claim, $amount, $settlement, $method, $shopAccountId, $userId) {
            $claim = SchemeClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 2);

            if ($claim->status !== SchemeClaimStatus::Submitted) {
                throw ValidationException::withMessages(['settleAmount' => 'This claim is already '.$claim->status.'.']);
            }

            if ($amount < 0 || $amount > round((float) $claim->claimed_amount, 2)) {
                throw ValidationException::withMessages(['settleAmount' => 'Enter up to the Rs '.number_format((float) $claim->claimed_amount, 2).' claimed.']);
            }

            $credit = null;

            if ($amount > 0) {
                $credit = VendorCredits::issue($claim->vendor_id, $amount, VendorCreditSource::Claim, $claim->id, $userId, $claim->number(), $settlement === 'credit');

                if ($settlement === 'cash') {
                    VendorCredits::refund($credit, $amount, $method, $shopAccountId, $userId);
                }
            }

            $claim->update([
                'status' => SchemeClaimStatus::Settled,
                'settled_amount' => $amount,
                'settled_on' => now()->toDateString(),
                'settlement' => $settlement,
                'payment_method' => $settlement === 'cash' ? $method : null,
                'shop_account_id' => $settlement === 'cash' ? $shopAccountId : null,
                'vendor_credit_id' => $credit?->id,
            ]);
        });
    }

    /** Withdraws a claim not yet settled: its free goods can be claimed again. */
    public static function cancelClaim(SchemeClaim $claim): void
    {
        DB::transaction(function () use ($claim) {
            $claim = SchemeClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();

            if ($claim->status !== SchemeClaimStatus::Submitted) {
                throw ValidationException::withMessages(['claim' => 'Only a claim not yet settled can be withdrawn.']);
            }

            SchemeRedemption::where('scheme_claim_id', $claim->id)->update(['scheme_claim_id' => null]);
            $claim->update(['status' => SchemeClaimStatus::Cancelled]);
        });
    }

    /**
     * Per company: free goods earned and not claimed, claimed and waiting,
     * and settled.
     *
     * @return Collection<int, array{vendor_id: int, unclaimed: float, waiting: float, settled: float, short: float}>
     */
    public static function summary(): Collection
    {
        $unclaimed = SchemeRedemption::query()->whereNull('scheme_claim_id')
            ->whereHas('sale', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', SaleStatus::Cancelled->value))
            ->with('scheme:id,vendor_id')->get()
            ->filter(fn (SchemeRedemption $r) => $r->scheme?->vendor_id !== null)
            ->groupBy(fn (SchemeRedemption $r) => $r->scheme->vendor_id)
            ->map(fn ($rows) => round((float) $rows->sum('cost_value'), 2));

        $claims = SchemeClaim::query()->where('status', '!=', SchemeClaimStatus::Cancelled->value)->get()->groupBy('vendor_id');
        $vendorIds = $unclaimed->keys()->merge($claims->keys())->unique();

        return $vendorIds->map(fn ($id) => [
            'vendor_id' => (int) $id,
            'unclaimed' => $unclaimed[$id] ?? 0.0,
            'waiting' => round((float) ($claims[$id] ?? collect())->where('status', SchemeClaimStatus::Submitted)->sum('claimed_amount'), 2),
            'settled' => round((float) ($claims[$id] ?? collect())->where('status', SchemeClaimStatus::Settled)->sum('settled_amount'), 2),
            'short' => round((float) ($claims[$id] ?? collect())->where('status', SchemeClaimStatus::Settled)->sum(fn ($c) => (float) $c->claimed_amount - (float) $c->settled_amount), 2),
        ])->values();
    }
}
