<?php

namespace App\Models;

use App\Enums\VendorInvoiceStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor's bill for goods already received. Stores no balance: paid and owed
 * are always summed from its stock_ins rows. Never edited: a wrong one is
 * cancelled, which frees its lines and number for a corrected one.
 */
class VendorInvoice extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'user_id',
        'invoice_number',
        'invoice_date',
        'note',
        'status',
        'auto_passed',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'active_marker',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'flagged', 'active_marker' => 1];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'status' => VendorInvoiceStatus::class,
            'auto_passed' => 'boolean',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class)->orderBy('id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(VendorInvoiceCharge::class)->orderBy('id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function number(): string
    {
        return 'VI-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === VendorInvoiceStatus::Cancelled;
    }

    public function isFlagged(): bool
    {
        return $this->status === VendorInvoiceStatus::Flagged;
    }

    public function isApproved(): bool
    {
        return $this->status === VendorInvoiceStatus::Approved;
    }

    public function billedTotal(): float
    {
        return round((float) $this->lines->sum(fn (VendorInvoiceLine $line) => (float) $line->billed_amount) + (float) $this->charges->sum(fn (VendorInvoiceCharge $charge) => (float) $charge->amount), 2);
    }

    public function amountOwed(): float
    {
        return round((float) $this->lines->sum(fn (VendorInvoiceLine $line) => $line->stockIn?->amountOwed() ?? 0.0), 2);
    }

    public function amountPaid(): float
    {
        return round((float) $this->lines->sum(fn (VendorInvoiceLine $line) => (float) ($line->stockIn?->amount_paid ?? 0)), 2);
    }

    /** Lists: invoices touching the branch being looked at. An invoice itself belongs to the shop. */
    public function scopeTouchingCurrentBranch(Builder $query): Builder
    {
        $branchId = BranchContext::current()->scopeBranchId();

        return $branchId === null
            ? $query
            : $query->whereHas('lines.stockIn', fn (Builder $row) => $row->where('stock_ins.branch_id', $branchId));
    }

    /** Branch-restricted staff can act only when every line is their own branch's. */
    public function isWithinUsersBranch(): bool
    {
        $restricted = BranchContext::current()->restrictedBranchId();

        return $restricted === null
            || $this->lines->every(fn (VendorInvoiceLine $line) => (int) $line->stockIn?->branch_id === (int) $restricted);
    }
}
