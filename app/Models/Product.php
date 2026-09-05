<?php

namespace App\Models;

use App\Enums\SimForm;
use App\Enums\SimType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToShop, HasImage;

    public const LOW_STOCK_THRESHOLD = 5;

    protected $fillable = [
        'shop_id',
        'type',
        'name',
        'image_path',
        'price',
        'cost_price',
        'stock_quantity',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'details' => 'array',
        ];
    }

    public function mainCategory(): BelongsTo
    {
        return $this->belongsTo(MainCategory::class, 'type', 'slug');
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= self::LOW_STOCK_THRESHOLD;
    }

    /**
     * The Main Category's own display name, except for legacy SIM products —
     * SIM stopped being a Main Category (or a creatable product type) before
     * Main Categories existed, so no row can ever represent it.
     */
    public function typeLabel(): string
    {
        if ($this->type === 'sim') {
            return 'SIM / eSIM';
        }

        return $this->mainCategory?->name ?? ucfirst($this->type);
    }

    public function summaryLine(): string
    {
        return match ($this->type) {
            'mobile' => trim(
                ($this->details['brand'] ?? '').' '.($this->details['model'] ?? '')
            ).(($this->details['imei'] ?? null) ? ' · IMEI '.$this->details['imei'] : ''),

            'sim' => collect([
                isset($this->details['sim_type']) ? SimType::from($this->details['sim_type'])->label() : null,
                isset($this->details['sim_form']) ? SimForm::from($this->details['sim_form'])->label() : null,
                $this->details['network'] ?? null,
            ])->filter()->implode(' · '),

            default => $this->details['category'] ?? '',
        };
    }
}
