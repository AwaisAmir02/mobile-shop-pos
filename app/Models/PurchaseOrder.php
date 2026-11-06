<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * What was ordered from a vendor. Moves no stock and no money: that happens
 * when goods are received. branch_id is the branch expecting the goods.
 */
class PurchaseOrder extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'user_id',
        'order_date',
        'expected_date',
        'note',
        'status',
        'closed_at',
        'closed_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'open'];

    /** Visible across branches like Stock-In; lists narrow it with inCurrentBranch(). */
    public static function appliesBranchScope(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PoLine::class)->orderBy('id');
    }

    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class)->latest('received_on')->latest('id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function number(): string
    {
        return 'PO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseOrderStatus::Cancelled;
    }

    public function hasActiveGrn(): bool
    {
        return $this->grns()->exists();
    }

    /** Lines can change only until goods have arrived against them. */
    public function isEditable(): bool
    {
        return $this->status === PurchaseOrderStatus::Open && ! $this->hasActiveGrn();
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [PurchaseOrderStatus::Open, PurchaseOrderStatus::PartiallyReceived], true);
    }

    /**
     * Status follows what the active GRN lines add up to, right now. The one
     * place it's worked out, always inside the transaction that changed them.
     */
    public function recomputeStatus(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $lines = $this->lines()->with('activeGrnLines')->get();

        $this->status = match (true) {
            $this->closed_at !== null => PurchaseOrderStatus::Closed,
            $lines->isNotEmpty() && $lines->every(fn (PoLine $line) => Quantity::compare($line->outstandingQuantity(), 0) === 0) => PurchaseOrderStatus::FullyReceived,
            $lines->contains(fn (PoLine $line) => Quantity::compare($line->receivedQuantity(), 0) > 0) => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Open,
        };

        $this->save();
    }

    /** "5 pieces · 2.5 kg" still to come; units are never added together. */
    public function outstandingSummary(?Collection $lines = null): string
    {
        $byUnit = ($lines ?? $this->lines)
            ->groupBy(fn (PoLine $line) => $line->unit->value)
            ->map(fn (Collection $group) => Quantity::sum($group->map(fn (PoLine $line) => $line->outstandingQuantity())))
            ->filter(fn ($total) => Quantity::compare($total, 0) > 0);

        $pieces = $byUnit->pull(QuantityUnit::Piece->value);

        return collect([
            $pieces !== null ? Quantity::grouped($pieces).' '.str('piece')->plural($pieces) : null,
            QuantityUnit::summarize($byUnit->all()) ?: null,
        ])->filter()->implode(' · ');
    }

    public function outstandingAmount(?Collection $lines = null): float
    {
        return round(($lines ?? $this->lines)->sum(fn (PoLine $line) => $line->expected_cost !== null ? Quantity::money($line->expected_cost, $line->outstandingQuantity()) : 0.0), 2);
    }

    public function orderedAmount(?Collection $lines = null): float
    {
        return round(($lines ?? $this->lines)->sum(fn (PoLine $line) => $line->expected_cost !== null ? Quantity::money($line->expected_cost, $line->quantity) : 0.0), 2);
    }
}
