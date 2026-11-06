<?php

namespace App\Models;

use App\Enums\QuantityUnit;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The parent of a set of variants: what they share (name, category, image).
 * Each variant is a products row with its own barcode, stock, price and FIFO
 * batches. See ProductGroupService for the rules that keep the two in step.
 */
class ProductGroup extends Model
{
    use BelongsToShop, HasImage;

    /** IMEI-tracked mobiles and legacy SIMs are one physical unit per row, so they never have variants. */
    public const EXCLUDED_TYPES = ['mobile', 'sim'];

    protected $fillable = [
        'shop_id',
        'name',
        'type',
        'unit',
        'details',
        'image_path',
    ];

    protected $attributes = ['unit' => 'piece'];

    protected function casts(): array
    {
        return [
            'unit' => QuantityUnit::class,
            'details' => 'array',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('id');
    }
}
