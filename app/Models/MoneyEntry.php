<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Cash & Balances needs that no other record holds: an opening balance,
 * money moved between cash and accounts, or a counted balance. See
 * App\Support\MoneyFlow for how balances are worked out.
 *
 * A side is a method ('cash' at branch_id, or 'account' with a shop account).
 */
class MoneyEntry extends Model
{
    use BelongsToShop, HasCancellation;

    public const OPENING = 'opening';

    public const TRANSFER = 'transfer';

    public const COUNT = 'count';

    protected $fillable = [
        'shop_id',
        'branch_id',
        'user_id',
        'kind',
        'entry_date',
        'from_method',
        'from_shop_account_id',
        'to_method',
        'to_shop_account_id',
        'amount',
        'counted',
        'expected',
        'note',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'decimal:2',
            'counted' => 'decimal:2',
            'expected' => 'decimal:2',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'from_shop_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(ShopAccount::class, 'to_shop_account_id');
    }

    /** The bucket key a side belongs to, as MoneyFlow names them. */
    public static function bucketKey(?string $method, ?int $shopAccountId, ?int $branchId): ?string
    {
        return match (true) {
            $method === 'account' && $shopAccountId !== null => 'account:'.$shopAccountId,
            $method === 'cash' => 'cash:'.($branchId ?? 0),
            default => null,
        };
    }

    public function fromBucket(): ?string
    {
        return self::bucketKey($this->from_method, $this->from_shop_account_id, $this->branch_id);
    }

    public function toBucket(): ?string
    {
        return self::bucketKey($this->to_method, $this->to_shop_account_id, $this->branch_id);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::OPENING => 'Opening balance',
            self::TRANSFER => 'Money moved',
            self::COUNT => 'Count',
            default => ucfirst((string) $this->kind),
        };
    }
}
