<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A message sent to many customers at once (App\Actions\Broadcasts). */
class Broadcast extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'audience', 'body', 'attachment_path', 'attachment_name', 'user_id'];

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
