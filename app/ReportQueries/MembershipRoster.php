<?php

namespace App\ReportQueries;

use App\Actions\Memberships;
use App\Enums\MemberStanding;
use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\Membership;
use App\Support\CustomerDuesStatement;
use Illuminate\Support\Collection;

/**
 * Members and where each stands today: the Memberships screen's list. One row
 * per customer holding any membership or package, with the one that decides
 * their standing (Memberships::standing) and what they owe.
 */
class MembershipRoster
{
    public function __construct(private readonly int $shopId, private readonly string $date) {}

    /** @return Collection<int, array{customer: Customer, standing: MemberStanding, membership: ?Membership, dues: float, ends_on: ?string, sessions_left: ?int}> */
    public function rows(?string $filter = null, string $search = ''): Collection
    {
        $customerIds = Membership::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('status', RecordStatus::Active->value)->distinct()->pluck('customer_id');

        return Customer::withoutGlobalScopes()
            ->where('shop_id', $this->shopId)
            ->whereIn('id', $customerIds)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('member_no', $search)))
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) {
                $standing = Memberships::standing($customer, $this->date);

                return [
                    'customer' => $customer,
                    'standing' => $standing['standing'],
                    'membership' => $standing['membership'],
                    'dues' => round(max(0.0, CustomerDuesStatement::for($customer)->total()), 2),
                    'ends_on' => $standing['membership']?->ends_on?->toDateString(),
                    'sessions_left' => $standing['membership']?->sessionsLeft(),
                ];
            })
            ->filter(fn (array $row) => match ($filter) {
                'active' => $row['standing'] === MemberStanding::Active,
                'expiring' => $row['standing'] === MemberStanding::Active && $row['ends_on'] !== null && $row['ends_on'] <= now('Asia/Karachi')->addDays(7)->toDateString(),
                'expired' => in_array($row['standing'], [MemberStanding::Expired, MemberStanding::UsedUp], true),
                'frozen' => $row['standing'] === MemberStanding::Frozen,
                'dues' => $row['dues'] > 0,
                default => true,
            })
            ->values();
    }

    /** @return array<string, int> counts for the filter chips */
    public function counts(): array
    {
        $rows = $this->rows();

        return [
            'all' => $rows->count(),
            'active' => $rows->where('standing', MemberStanding::Active)->count(),
            'expiring' => $rows->filter(fn ($r) => $r['standing'] === MemberStanding::Active && $r['ends_on'] !== null && $r['ends_on'] <= now('Asia/Karachi')->addDays(7)->toDateString())->count(),
            'expired' => $rows->filter(fn ($r) => in_array($r['standing'], [MemberStanding::Expired, MemberStanding::UsedUp], true))->count(),
            'frozen' => $rows->where('standing', MemberStanding::Frozen)->count(),
            'dues' => $rows->filter(fn ($r) => $r['dues'] > 0)->count(),
        ];
    }
}
