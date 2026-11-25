<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A distributor's beat (W8): the customers a booker visits, in order, on given days. */
class DeliveryRoute extends Model
{
    use BelongsToShop;

    public const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    protected $fillable = ['shop_id', 'name', 'visit_days', 'booker_id', 'is_active'];

    protected function casts(): array
    {
        return ['visit_days' => 'array', 'is_active' => 'boolean'];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'route_id')->orderByRaw('route_sequence IS NULL')->orderBy('route_sequence')->orderBy('name');
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booker_id');
    }

    public function daysLabel(): string
    {
        return collect($this->visit_days ?? [])->map(fn ($day) => self::DAYS[$day] ?? $day)->implode(', ') ?: 'Any day';
    }
}
