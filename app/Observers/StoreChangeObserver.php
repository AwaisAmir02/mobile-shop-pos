<?php

namespace App\Observers;

use App\Enums\WhatsAppOrderStatus;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockIn;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Support\StoreChanges;
use App\Support\StoreEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * Notices whatever moves availability or a product's details. Listens to updated as
 * well as saved because $product->decrement(), how every stock writer moves stock,
 * fires updated and not saved. Only ever writes a marker: see StoreChanges.
 */
class StoreChangeObserver
{
    /** What a store is shown about a product, beyond its stock. */
    protected const CATALOG_FIELDS = ['name', 'barcode', 'image_path', 'unit', 'type', 'details', 'archived_at', 'variant_label', 'product_group_id', 'price'];

    public function created(Model $model): void
    {
        $this->handle($model);
    }

    public function updated(Model $model): void
    {
        $this->handle($model);
    }

    public function deleted(Model $model): void
    {
        $this->handle($model);
    }

    /** Its rows on each connection go with it, so tell the stores while they exist. */
    public function deleting(Model $model): void
    {
        if (! $model instanceof Product) {
            return;
        }

        foreach (StoreProduct::withoutGlobalScopes()->where('product_id', $model->id)->where('published', true)->get() as $row) {
            $connection = StoreConnection::withoutGlobalScopes()->find($row->store_connection_id);

            if ($connection !== null) {
                StoreEvents::productUnpublished($connection, $row);
            }
        }
    }

    protected function handle(Model $model): void
    {
        $changes = StoreChanges::current();

        match (true) {
            $model instanceof Product => $this->product($model, $changes),
            $model instanceof ProductGroup => $model->variants()->withoutGlobalScopes()->pluck('id')->each(fn ($id) => $changes->catalogChanged((int) $model->shop_id, (int) $id)),
            $model instanceof StockIn => $changes->stockChanged((int) $model->shop_id, (int) $model->product_id),
            $model instanceof StoreProduct => $changes->publicationChanged($model),
            $model instanceof StoreOrderItem => $model->product_id !== null ? $changes->stockChanged((int) $model->shop_id, (int) $model->product_id) : null,
            $model instanceof StoreOrder => $model->wasChanged('status') ? $this->products($model->items()->withoutGlobalScopes()->pluck('product_id'), (int) $model->shop_id, $changes) : null,
            $model instanceof WhatsAppOrder => $model->wasChanged('status') ? $this->products(WhatsAppOrderItem::withoutGlobalScopes()->where('whatsapp_order_id', $model->id)->pluck('product_id'), (int) $model->shop_id, $changes) : null,
            default => null,
        };
    }

    protected function product(Product $product, StoreChanges $changes): void
    {
        $changes->stockChanged((int) $product->shop_id, (int) $product->id);

        if ($product->wasChanged(self::CATALOG_FIELDS)) {
            $changes->catalogChanged((int) $product->shop_id, (int) $product->id);
        }
    }

    protected function products($ids, int $shopId, StoreChanges $changes): void
    {
        foreach ($ids->filter() as $id) {
            $changes->stockChanged($shopId, (int) $id);
        }
    }
}
