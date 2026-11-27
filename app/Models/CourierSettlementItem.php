<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parcel on a courier payout: what the courier collected for it (nothing
 * for a returned parcel) and what it charged to carry it there and back.
 */
class CourierSettlementItem extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'courier_settlement_id', 'shipment_id', 'sale_id', 'cod_collected', 'courier_charge', 'return_charge', 'sale_payment_id'];

    protected function casts(): array
    {
        return ['cod_collected' => 'decimal:2', 'courier_charge' => 'decimal:2', 'return_charge' => 'decimal:2'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScope(ExcludesCancelledSalesScope::class);
    }
}
