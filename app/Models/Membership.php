<?php

namespace App\Models;

use App\Enums\MembershipKind;
use App\Enums\RecordStatus;
use App\Enums\SaleLineKind;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's membership or package, made from the sale line that sold it
 * (App\Actions\Memberships). The plan's terms are copied on, so editing a plan
 * never changes one already sold. Sessions used are the package lines on
 * sales that aren't cancelled; days frozen push ends_on out.
 */
class Membership extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'customer_id', 'membership_plan_id', 'sale_id', 'sale_item_id', 'plan_name', 'kind', 'price', 'starts_on', 'ends_on', 'sessions_total', 'service_item_ids', 'max_freeze_days', 'status', 'cancelled_at', 'renewal_reminded_at'];

    protected $attributes = ['status' => 'active', 'max_freeze_days' => 0];

    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'status' => RecordStatus::class,
            'price' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'sessions_total' => 'integer',
            'service_item_ids' => 'array',
            'max_freeze_days' => 'integer',
            'cancelled_at' => 'datetime',
            'renewal_reminded_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    public function freezes(): HasMany
    {
        return $this->hasMany(MembershipFreeze::class)->where('status', RecordStatus::Active->value)->orderBy('from_date');
    }

    public function number(): string
    {
        return 'MB-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === RecordStatus::Cancelled;
    }

    /** Sessions taken on sales that still stand. */
    public function sessionsUsed(): int
    {
        if ($this->kind !== MembershipKind::Sessions) {
            return 0;
        }

        return (int) SaleItem::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.membership_id', $this->id)
            ->where('sale_items.line_kind', SaleLineKind::Package->value)
            ->where('sales.status', '!=', 'cancelled')
            ->sum('sale_items.quantity');
    }

    public function sessionsLeft(): ?int
    {
        return $this->kind === MembershipKind::Sessions ? max(0, (int) $this->sessions_total - $this->sessionsUsed()) : null;
    }

    public function daysFrozen(): int
    {
        return (int) $this->freezes->sum(fn (MembershipFreeze $f) => $f->days());
    }

    /** Whether a package's sessions can be spent on this service. */
    public function covers(?int $serviceItemId): bool
    {
        $ids = array_map('intval', $this->service_item_ids ?? []);

        return $ids === [] || ($serviceItemId !== null && in_array($serviceItemId, $ids, true));
    }

    /** What one session is worth, for commission on a session taken: the price over the sessions. */
    public function sessionValue(): float
    {
        return $this->sessions_total > 0 ? round((float) $this->price / $this->sessions_total, 2) : 0.0;
    }
}
