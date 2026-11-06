<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the vendor billed for one received batch. billed_amount is the line
 * total as printed, so "Rs 100 for 3" stays exact. previous_* is what approval
 * replaced, which is what cancelling an approved invoice restores.
 */
class VendorInvoiceLine extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'vendor_invoice_id',
        'grn_line_id',
        'stock_in_id',
        'billed_quantity',
        'billed_amount',
        'amended',
        'previous_cost_price',
        'previous_total_cost',
        'unsold_quantity_at_approval',
        'active_marker',
    ];

    protected $attributes = ['active_marker' => 1];

    protected function casts(): array
    {
        return [
            'billed_quantity' => QuantityCast::class,
            'billed_amount' => 'decimal:2',
            'amended' => 'boolean',
            'previous_cost_price' => 'decimal:2',
            'previous_total_cost' => 'decimal:2',
            'unsold_quantity_at_approval' => QuantityCast::class,
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class, 'vendor_invoice_id');
    }

    public function grnLine(): BelongsTo
    {
        return $this->belongsTo(GrnLine::class);
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class)->withoutGlobalScope(ExcludesCancelledScope::class);
    }

    /** What the goods were received at, even after approval corrected the row. */
    public function receivedTotal(): ?float
    {
        $total = $this->amended ? $this->previous_total_cost : $this->stockIn?->total_cost;

        return $total !== null ? (float) $total : null;
    }

    /** For the approval screen: real numbers, so nobody wonders why Gross Profit didn't move. */
    public function soldNote(): string
    {
        $row = $this->stockIn;
        $sold = Quantity::subtract($row->quantity, $row->quantity_remaining);
        $label = $row->consumedLabel();
        $amount = fn ($quantity) => $row->unit->amount($quantity);
        $owed = 'What you owe the vendor changes in full.';

        return match (true) {
            Quantity::compare($sold, 0) === 0 => "None {$label} yet, so all {$amount($row->quantity)} use the new cost. {$owed}",
            Quantity::compare($row->quantity_remaining, 0) === 0 => "All {$amount($row->quantity)} already {$label}. They keep the old cost in Gross Profit. {$owed}",
            default => "{$amount($sold)} of {$amount($row->quantity)} already {$label}. Those keep the old cost in Gross Profit; the {$amount($row->quantity_remaining)} unsold use the new cost. {$owed}",
        };
    }
}
