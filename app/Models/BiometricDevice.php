<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fingerprint device that pushes to us over ZKTeco's ADMS protocol
 * (App\Http\Controllers\Biometric\AdmsController). The protocol identifies a
 * device by its serial number only, so only a serial a shop registered here is
 * answered.
 */
class BiometricDevice extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'branch_id', 'name', 'serial_number', 'is_active', 'remove_expired', 'last_seen_at', 'last_ip', 'push_version', 'firmware', 'att_stamp', 'op_stamp'];

    protected $attributes = ['is_active' => true, 'remove_expired' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'remove_expired' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(BiometricCommand::class);
    }

    /** Heard from in the last ten minutes (an online device asks for commands every few seconds). */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(10));
    }
}
