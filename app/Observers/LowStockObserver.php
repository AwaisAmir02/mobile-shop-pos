<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\LowStockAlerts;

class LowStockObserver
{
    public function updated(Product $product): void
    {
        app(LowStockAlerts::class)->check($product);
    }
}
