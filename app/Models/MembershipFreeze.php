<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** Days a membership was paused; the membership's end moves out by the same days. */
class MembershipFreeze extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'membership_id', 'from_date', 'to_date', 'reason', 'status', 'user_id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'status' => RecordStatus::class];
    }

    public function days(): int
    {
        return (int) $this->from_date->diffInDays($this->to_date) + 1;
    }
}
