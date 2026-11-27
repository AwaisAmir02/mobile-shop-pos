<?php

namespace App\Models;

use App\Enums\MarketplaceImportKind;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Daraz Seller Center file brought in and what it did (App\Actions\DarazImports). */
class MarketplaceImport extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'store_connection_id', 'kind', 'file_name', 'rows', 'summary', 'courier_settlement_id', 'user_id'];

    protected function casts(): array
    {
        return ['kind' => MarketplaceImportKind::class, 'summary' => 'array', 'rows' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
