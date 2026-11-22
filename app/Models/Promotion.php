<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A percentage or flat amount off, between two dates, on some or all of the
 * shop's products. See App\Actions\Promotions for how a line gets one.
 */
class Promotion extends Model
{
    use BelongsToShop;

    public const PERCENT = 'percent';

    public const FLAT = 'flat';

    public const ALL = 'all';

    public const PRODUCTS = 'products';

    public const GROUPS = 'groups';

    public const CATEGORIES = 'categories';

    protected $fillable = [
        'shop_id',
        'name',
        'kind',
        'value',
        'applies_to',
        'targets',
        'starts_on',
        'ends_on',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'targets' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** Running on that day (Y-m-d): switched on and within its dates. */
    public function runsOn(string $date): bool
    {
        return $this->is_active
            && $this->starts_on->toDateString() <= $date
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    /** "Running", "Starts 1 Dec", "Ended", "Paused". */
    public function statusOn(string $date): string
    {
        return match (true) {
            ! $this->is_active => 'Paused',
            $this->starts_on->toDateString() > $date => 'Starts '.$this->starts_on->format('j M'),
            $this->ends_on !== null && $this->ends_on->toDateString() < $date => 'Ended',
            default => 'Running',
        };
    }

    public function covers(Product $product): bool
    {
        $targets = array_map('strval', (array) $this->targets);

        return match ($this->applies_to) {
            self::ALL => true,
            self::PRODUCTS => in_array((string) $product->id, $targets, true),
            self::GROUPS => $product->product_group_id !== null && in_array((string) $product->product_group_id, $targets, true),
            self::CATEGORIES => in_array((string) $product->type, $targets, true),
            default => false,
        };
    }

    /** "20% off" or "Rs 100 off each". */
    public function offerLabel(): string
    {
        return $this->kind === self::PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : 'Rs '.rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').' off each';
    }

    /** What it costs a line: its share of the line's amount, never more than the line. */
    public function discountOn(float $amount, int|float $quantity): float
    {
        $off = $this->kind === self::PERCENT
            ? round($amount * (float) $this->value / 100, 2)
            : round((float) $this->value * (float) $quantity, 2);

        return round(max(0.0, min($off, $amount)), 2);
    }

    /** Whether a sale has used it; its discount and what it covers are then fixed. */
    public function hasBeenUsed(): bool
    {
        return SaleItem::withoutGlobalScopes()->where('promotion_id', $this->id)->exists();
    }
}
