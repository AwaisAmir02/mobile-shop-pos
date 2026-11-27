<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * A fingerprint template a device sent up, encrypted at rest. Kept only so a
 * member a device removed (expired membership) can be put back without
 * enrolling the finger again. Deleted with the shop.
 */
class BiometricTemplate extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'pin', 'finger', 'template', 'size'];

    protected $hidden = ['template'];

    protected function casts(): array
    {
        return ['template' => 'encrypted', 'pin' => 'integer', 'finger' => 'integer', 'size' => 'integer'];
    }
}
