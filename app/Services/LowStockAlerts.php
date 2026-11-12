<?php

namespace App\Services;

use App\Mail\LowStockMail;
use App\Models\Product;
use App\Models\Shop;
use App\Support\ShopOwnerEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the shop owner when a product gets the "Low" badge
 * (Product::isLowStock()). Products crossing in one transaction, e.g. a
 * sale of several items, share one email sent after it commits.
 */
class LowStockAlerts
{
    /** @var array<int, array<int, array{name: string, stock: string}>> shop id => product id => item */
    protected array $pending = [];

    /** @var array<int, int> shop id => transaction level its batch was opened at */
    protected array $levels = [];

    /** Called after a product is saved: queue it if it just went from in stock to low. */
    public function check(Product $product): void
    {
        if (! $product->wasChanged('stock_quantity') || ! $product->isLowStock() || $product->isArchived()) {
            return;
        }

        $before = $product->getOriginal('stock_quantity');

        if ($before === null || $before <= Product::LOW_STOCK_THRESHOLD) {
            return;
        }

        $shopId = (int) $product->shop_id;
        $first = ! isset($this->pending[$shopId]);
        $this->pending[$shopId][$product->id] = ['name' => (string) $product->name, 'stock' => $product->stockLabel()];

        if ($first) {
            $this->levels[$shopId] = DB::transactionLevel();
            // Runs straight away outside a transaction, and only on commit inside one.
            DB::afterCommit(fn () => $this->flush($shopId));
        }
    }

    /** A rollback drops its afterCommit callbacks, so drop the batches it opened too. */
    public function rolledBack(): void
    {
        $level = DB::transactionLevel();

        foreach ($this->levels as $shopId => $openedAt) {
            if ($openedAt > $level) {
                unset($this->pending[$shopId], $this->levels[$shopId]);
            }
        }
    }

    public function flush(int $shopId): void
    {
        $items = array_values($this->pending[$shopId] ?? []);
        unset($this->pending[$shopId], $this->levels[$shopId]);

        if ($items === [] || ! ($shop = Shop::find($shopId)) || ! ($email = ShopOwnerEmail::for($shop))) {
            return;
        }

        try {
            Mail::to($email)->queue(new LowStockMail($shop, $items));
        } catch (\Throwable $e) {
            Log::warning('Low stock email could not be queued.', ['shop' => $shopId, 'error' => $e->getMessage()]);
        }
    }
}
