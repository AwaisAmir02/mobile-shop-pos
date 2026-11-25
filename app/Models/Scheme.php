<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trade scheme (W9): buy so many of a product, get so many free, between
 * two dates. When a company (vendor) funds it, the free goods given are
 * claimed back from them at cost (SchemeClaim).
 */
class Scheme extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'name', 'product_id', 'vendor_id', 'buy_quantity', 'free_quantity', 'starts_on', 'ends_on', 'is_active'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean', 'buy_quantity' => 'integer', 'free_quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(SchemeRedemption::class);
    }

    public function offerLabel(): string
    {
        return $this->buy_quantity.' + '.$this->free_quantity.' free';
    }

    public function runsOn(string $date): bool
    {
        return $this->is_active && $this->starts_on->toDateString() <= $date && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    /** Free pieces earned by buying $paid pieces. */
    public function freeFor(int|float $paid): int
    {
        return $this->buy_quantity > 0 ? intdiv((int) floor((float) $paid), $this->buy_quantity) * $this->free_quantity : 0;
    }
}
