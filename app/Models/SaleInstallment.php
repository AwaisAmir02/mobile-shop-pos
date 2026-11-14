<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due date in a sale's instalment plan. It records when part of the sale
 * falls due, not a payment: payments stay in sale_payments, and which
 * instalments they cover is worked out by App\Support\InstallmentSchedule.
 */
class SaleInstallment extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'sale_id', 'sequence', 'due_date', 'amount'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
