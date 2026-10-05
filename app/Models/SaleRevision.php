<?php

namespace App\Models;

use App\Enums\SaleRevisionAction;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleRevision extends Model
{
    use BelongsToShop;

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'action',
        'user_id',
        'reason',
        'snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => SaleRevisionAction::class,
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
