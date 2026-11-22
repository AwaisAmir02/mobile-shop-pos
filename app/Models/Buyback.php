<?php

namespace App\Models;

use App\Enums\BuybackCondition;
use App\Enums\RecordStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasCancellation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A used phone bought from a customer. See App\Actions\Buybacks. */
class Buyback extends Model
{
    use BelongsToShop, BelongsToBranch, HasCancellation;

    protected $fillable = [
        'shop_id',
        'branch_id',
        'user_id',
        'customer_id',
        'product_id',
        'stock_in_id',
        'sale_id',
        'sale_payment_id',
        'seller_name',
        'seller_phone',
        'seller_cnic',
        'brand',
        'model',
        'imei',
        'condition',
        'battery_health',
        'pta_approved',
        'has_box',
        'has_charger',
        'price',
        'selling_price',
        'bought_on',
        'note',
        'reused_product',
        'product_before',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'condition' => BuybackCondition::class,
            'battery_health' => 'integer',
            'pta_approved' => 'boolean',
            'has_box' => 'boolean',
            'has_charger' => 'boolean',
            'price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'bought_on' => 'date',
            'reused_product' => 'boolean',
            'product_before' => 'array',
            'status' => RecordStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class);
    }

    /** The sale this phone was traded in on, if it was a trade-in. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withoutGlobalScopes();
    }

    public function isTradeIn(): bool
    {
        return $this->sale_id !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phoneName(): string
    {
        return trim($this->brand.' '.$this->model);
    }

    /** Sold on (or out of stock): the batch has nothing left, so it can't be cancelled here any more. */
    public function isSold(): bool
    {
        $batch = StockIn::withoutGlobalScopes()->find($this->stock_in_id);

        return $batch !== null && (float) $batch->quantity_remaining <= 0 && ! $this->isCancelled();
    }
}
