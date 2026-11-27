<?php

namespace App\Models;

use App\Enums\MembershipKind;
use App\Models\Concerns\BelongsToShop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A membership or package a service business sells (gym month, 10 facials).
 * Called a plan only inside this model: the app's own billing already uses
 * Plan / Subscription, so the screens say "membership" and "package".
 */
class MembershipPlan extends Model
{
    use BelongsToShop;

    public const UNITS = ['days' => 'Days', 'months' => 'Months'];

    protected $fillable = ['shop_id', 'name', 'kind', 'price', 'duration_value', 'duration_unit', 'sessions', 'service_item_ids', 'max_freeze_days', 'is_active'];

    protected $attributes = ['max_freeze_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'price' => 'decimal:2',
            'duration_value' => 'integer',
            'sessions' => 'integer',
            'service_item_ids' => 'array',
            'max_freeze_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The last day of a membership starting on $start, or null when it never runs out by date. */
    public function endsOnFor(CarbonInterface $start): ?string
    {
        if (! $this->duration_value) {
            return null;
        }

        $end = $this->duration_unit === 'months'
            ? $start->copy()->addMonthsNoOverflow($this->duration_value)
            : $start->copy()->addDays($this->duration_value);

        return $end->subDay()->toDateString();
    }

    /** "1 month", "12 sessions within 90 days". */
    public function termLabel(): string
    {
        $time = null;

        if ($this->duration_value) {
            $unit = $this->duration_unit === 'months' ? 'month' : 'day';
            $time = $this->duration_value.' '.$unit.($this->duration_value === 1 ? '' : 's');
        }

        if ($this->kind === MembershipKind::Sessions) {
            return $this->sessions.' sessions'.($time !== null ? ' within '.$time : '');
        }

        return (string) $time;
    }
}
