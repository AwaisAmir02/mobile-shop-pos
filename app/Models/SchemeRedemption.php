<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Free goods given on one sale under a scheme, at the cost they left stock at. */
class SchemeRedemption extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'scheme_id', 'sale_id', 'product_id', 'free_quantity', 'cost_value', 'scheme_claim_id'];

    protected function casts(): array
    {
        return ['free_quantity' => QuantityCast::class, 'cost_value' => 'decimal:2'];
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }
}
