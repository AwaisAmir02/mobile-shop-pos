<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note on a client (a colour formula, an allergy, a fitness goal), shown with their visits. */
class CustomerNote extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'customer_id', 'body', 'pinned', 'user_id'];

    protected $attributes = ['pinned' => false];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
