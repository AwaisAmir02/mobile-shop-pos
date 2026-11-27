<?php

namespace App\Models;

use App\Enums\JobCardStatus;
use App\Enums\JobLineKind;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repair workshop's job (App\Actions\JobCards): the units brought in, what
 * was found, the parts and labour priced on it, and where it stands. Billed
 * once, through the register, which takes the parts from stock. The mobile
 * shop's Repairs module (App\Models\Repair) is a separate, older record.
 */
class JobCard extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'customer_id', 'technician_id', 'status', 'received_on', 'promised_on', 'site_visit', 'site_address', 'visit_charge', 'estimate_total', 'estimate_approved_at', 'estimate_approved_via', 'warranty_days', 'warranty_until', 'next_service_days', 'service_due_on', 'service_reminded_at', 'notes', 'ready_at', 'delivered_at', 'sale_id', 'user_id', 'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected $attributes = ['status' => 'received', 'site_visit' => false, 'visit_charge' => 0];

    protected function casts(): array
    {
        return [
            'status' => JobCardStatus::class,
            'received_on' => 'date',
            'promised_on' => 'date',
            'site_visit' => 'boolean',
            'visit_charge' => 'decimal:2',
            'estimate_total' => 'decimal:2',
            'estimate_approved_at' => 'datetime',
            'warranty_days' => 'integer',
            'warranty_until' => 'date',
            'next_service_days' => 'integer',
            'service_due_on' => 'date',
            'service_reminded_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobCardItem::class)->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JobCardLine::class)->orderBy('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CustomerDeposit::class);
    }

    public function number(): string
    {
        return 'JC-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function partsTotal(): float
    {
        return round($this->lines->filter(fn (JobCardLine $l) => $l->kind === JobLineKind::Part)->sum(fn (JobCardLine $l) => $l->total()), 2);
    }

    public function labourTotal(): float
    {
        return round($this->lines->filter(fn (JobCardLine $l) => $l->kind === JobLineKind::Labour)->sum(fn (JobCardLine $l) => $l->total()), 2);
    }

    /** Parts, labour and the visit charge: what the bill comes to before any discount. */
    public function total(): float
    {
        return round($this->partsTotal() + $this->labourTotal() + (float) $this->visit_charge, 2);
    }

    /** "Honda CD70 (LEB-1234), Haier 1.5 ton". */
    public function unitsLabel(): string
    {
        return $this->items->map(fn (JobCardItem $item) => $item->displayLabel())->implode(', ');
    }

    public function underWarrantyOn(string $date): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->toDateString() >= $date;
    }
}
