<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery against a Purchase Order. branch_id is the branch that actually
 * received it. Never edited: a wrong one is cancelled.
 */
class Grn extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'purchase_order_id',
        'user_id',
        'received_on',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    /**
     * A PO's received quantity must count every branch's deliveries, whoever
     * is looking, so this isn't a global scope. Lists use inCurrentBranch().
     */
    public static function appliesBranchScope(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GrnLine::class)->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function number(): string
    {
        return 'GRN-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
