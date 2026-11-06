<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;

/** Money the owner took out. It isn't a cost, so it doesn't belong in Expenses. Cancelled, never edited. */
class OwnerWithdrawal extends Model
{
    use BelongsToShop, HasCancellation;

    protected $fillable = ['shop_id', 'user_id', 'amount', 'paid_on', 'payment_method', 'shop_account_id', 'note', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'status' => RecordStatus::class, 'cancelled_at' => 'datetime'];
    }
}
