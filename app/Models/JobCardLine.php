<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\JobLineKind;
use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A part (from stock) or labour priced on a job card. */
class JobCardLine extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'job_card_id', 'job_card_item_id', 'kind', 'product_id', 'service_item_id', 'description', 'quantity', 'unit_price', 'employee_id'];

    protected function casts(): array
    {
        return ['kind' => JobLineKind::class, 'quantity' => QuantityCast::class, 'unit_price' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function total(): float
    {
        return Quantity::money($this->unit_price, $this->quantity);
    }
}
