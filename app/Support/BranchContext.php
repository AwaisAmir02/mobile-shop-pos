<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Which branch the current request is working in. Bound as a scoped
 * singleton; the active-branch lists are memoised per shop and dropped
 * whenever a Branch is saved.
 *
 * - A branch-restricted staff member (users.branch_id set, not the owner)
 *   always works in their own branch.
 * - Everyone else works in the branch picked in the switcher, or across
 *   all branches when none is picked. New records then go to Main.
 * - A shop with a single active branch never filters anything.
 */
class BranchContext
{
    public const SESSION_KEY = 'branch.selected';

    /** @var array<int, Collection<int, Branch>> */
    protected array $branches = [];

    protected int $shopWideDepth = 0;

    protected bool $shopWideForRequest = false;

    /** @var array{0: ?int}|null set while forBranch() runs; [null] means all branches */
    protected ?array $override = null;

    public static function current(): self
    {
        return app(self::class);
    }

    /** @return Collection<int, Branch> active branches, Main first */
    public function branchesFor(int $shopId): Collection
    {
        return $this->branches[$shopId] ??= Branch::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->active()
            ->ordered()
            ->get();
    }

    public function hasMultipleBranches(int $shopId): bool
    {
        return $this->branchesFor($shopId)->count() > 1;
    }

    public function mainBranchIdFor(int $shopId): int
    {
        $main = $this->branchesFor($shopId)->firstWhere('is_main', true)
            ?? Branch::withoutGlobalScopes()->where('shop_id', $shopId)->where('is_main', true)->first();

        if ($main === null) {
            $main = Branch::withoutGlobalScopes()->create(['shop_id' => $shopId, 'name' => 'Main', 'is_main' => true]);
        }

        return $main->id;
    }

    public function restrictedBranchId(?User $user = null): ?int
    {
        $user ??= Auth::user();

        return $user && ! $user->is_owner && $user->branch_id ? (int) $user->branch_id : null;
    }

    /** The branch the user is working in, or null for "All branches". */
    public function selectedBranchId(): ?int
    {
        $user = Auth::user();

        if (! $user?->shop_id) {
            return null;
        }

        if ($restricted = $this->restrictedBranchId($user)) {
            return $restricted;
        }

        if (! $this->hasMultipleBranches($user->shop_id)) {
            return null;
        }

        $selected = (int) session()->get(self::SESSION_KEY);

        return $this->branchesFor($user->shop_id)->contains('id', $selected) ? $selected : null;
    }

    public function select(?int $branchId): void
    {
        if ($branchId === null) {
            session()->forget(self::SESSION_KEY);
        } else {
            session()->put(self::SESSION_KEY, $branchId);
        }
    }

    /** What BranchScope filters by right now. */
    public function scopeBranchId(): ?int
    {
        if ($this->shopWideDepth > 0 || $this->shopWideForRequest) {
            return null;
        }

        if ($this->override !== null) {
            return $this->restrictedBranchId() ?? $this->override[0];
        }

        return $this->selectedBranchId();
    }

    /**
     * Runs $callback filtered to $branchId (null = all branches) instead of
     * the switcher's choice. Used for the per-branch breakdown of reports.
     * A branch-restricted user always stays on their own branch.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function forBranch(?int $branchId, callable $callback): mixed
    {
        $previous = $this->override;
        $this->override = [$branchId];

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    /** Where the logged-in user's new records go; null when nobody is logged in. */
    public function recordingBranchId(): ?int
    {
        $shopId = Auth::user()?->shop_id;

        return $shopId ? $this->recordingBranchIdFor((int) $shopId) : null;
    }

    /** Where a new record for $shopId is recorded. */
    public function recordingBranchIdFor(int $shopId): int
    {
        $user = Auth::user();

        if ($user && (int) $user->shop_id === $shopId && ($selected = $this->selectedBranchId())) {
            return $selected;
        }

        return $this->mainBranchIdFor($shopId);
    }

    /**
     * Runs $callback with BranchScope switched off. Balances and dues go
     * through here so a branch-restricted user and the owner always see
     * the same figures.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function shopWide(callable $callback): mixed
    {
        $this->shopWideDepth++;

        try {
            return $callback();
        } finally {
            $this->shopWideDepth--;
        }
    }

    /**
     * Switches BranchScope off for the rest of this request. Called from
     * boot() by the screens that are about a customer or vendor as a whole
     * (Customers, Vendors, Udhaar, Party Ledger), whose balances are always
     * shop-wide.
     */
    public function shopWideForRequest(): void
    {
        $this->shopWideForRequest = true;
    }

    public function forget(): void
    {
        $this->branches = [];
    }
}
