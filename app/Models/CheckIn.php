<?php

namespace App\Models;

use App\Enums\CheckInSource;
use App\Enums\MemberStanding;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member arriving, at the desk or by fingerprint (App\Actions\CheckIns). The
 * membership standing and dues are copied on at that moment, so the day's
 * list shows what the desk saw.
 */
class CheckIn extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'customer_id', 'membership_id', 'checked_in_at', 'source', 'biometric_device_id', 'device_pin', 'standing', 'dues', 'user_id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'source' => CheckInSource::class, 'standing' => MemberStanding::class, 'dues' => 'decimal:2', 'device_pin' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
