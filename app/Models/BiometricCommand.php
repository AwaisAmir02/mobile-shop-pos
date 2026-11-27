<?php

namespace App\Models;

use App\Enums\BiometricCommandStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** A command queued for a device: it collects it on its next getrequest and answers on devicecmd. */
class BiometricCommand extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'biometric_device_id', 'customer_id', 'command', 'status', 'return_code', 'sent_at', 'answered_at'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['status' => BiometricCommandStatus::class, 'return_code' => 'integer', 'sent_at' => 'datetime', 'answered_at' => 'datetime'];
    }
}
