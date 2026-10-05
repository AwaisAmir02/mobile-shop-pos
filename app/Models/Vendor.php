<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'name', 'phone', 'company_name', 'address', 'notes'];

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class)->latest('stock_date');
    }

    public function hasStockInHistory(): bool
    {
        return $this->stockIns()->exists();
    }

    public function totalOwed(): float
    {
        return (float) $this->stockIns->sum(fn (StockIn $entry) => $entry->amountOwed());
    }
}
