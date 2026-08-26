<?php

namespace App\Models;

use App\Enums\AccessoryCategory;
use App\Enums\ProductType;
use App\Enums\SimForm;
use App\Enums\SimType;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToShop;

    public const LOW_STOCK_THRESHOLD = 5;

    protected $fillable = [
        'shop_id',
        'type',
        'category_id',
        'sub_category_id',
        'name',
        'price',
        'cost_price',
        'stock_quantity',
        'details',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'details' => 'array',
        ];
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= self::LOW_STOCK_THRESHOLD;
    }

    public function summaryLine(): string
    {
        return match ($this->type) {
            ProductType::Mobile => trim(
                ($this->details['brand'] ?? '').' '.($this->details['model'] ?? '')
            ).(($this->details['imei'] ?? null) ? ' · IMEI '.$this->details['imei'] : ''),

            ProductType::Accessory => isset($this->details['category'])
                ? AccessoryCategory::from($this->details['category'])->label()
                : '',

            ProductType::Sim => collect([
                isset($this->details['sim_type']) ? SimType::from($this->details['sim_type'])->label() : null,
                isset($this->details['sim_form']) ? SimForm::from($this->details['sim_form'])->label() : null,
                $this->details['network'] ?? null,
            ])->filter()->implode(' · '),
        };
    }
}
