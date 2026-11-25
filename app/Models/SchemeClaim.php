<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Free goods claimed back from the company that funded the schemes (W9). */
class SchemeClaim extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'vendor_id', 'claimed_on', 'claimed_amount', 'status', 'settled_amount', 'settled_on', 'settlement', 'payment_method', 'shop_account_id', 'vendor_credit_id', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['claimed_on' => 'date', 'settled_on' => 'date', 'claimed_amount' => 'decimal:2', 'settled_amount' => 'decimal:2'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(SchemeRedemption::class);
    }

    public function number(): string
    {
        return 'CL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
