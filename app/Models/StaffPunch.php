<?php

namespace App\Models;

use App\Enums\CheckInSource;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clock-in or clock-out by a staff member (App\Actions\StaffAttendance):
 * a fingerprint punch, a scanned QR badge, or a tap at the desk. A day's
 * first punch is in and its last is out; the punches themselves never say.
 */
class StaffPunch extends Model
{
    use BelongsToShop, BelongsToBranch;

    protected $fillable = ['shop_id', 'branch_id', 'employee_id', 'punched_at', 'source', 'biometric_device_id', 'device_pin', 'user_id'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'source' => CheckInSource::class, 'device_pin' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
