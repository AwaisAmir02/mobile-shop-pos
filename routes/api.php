<?php

use App\Http\Controllers\Store\StoreApiController;
use Illuminate\Support\Facades\Route;

/*
 * The online store API, docs/channel-api-v1.md. Stateless, so no session or branch
 * switcher reaches it. Every route must name store.token, which scopes the request
 * to one shop; a test fails if a route here doesn't.
 */
Route::prefix('channel/v1')->middleware('throttle:store-api')->group(function () {
    Route::get('me', [StoreApiController::class, 'me'])->middleware('store.token')->name('store-api.me');
    Route::get('products', [StoreApiController::class, 'products'])->middleware('store.token:catalog:read')->name('store-api.products');
    Route::get('products/{id}', [StoreApiController::class, 'product'])->middleware('store.token:catalog:read')->name('store-api.product');
    Route::get('inventory', [StoreApiController::class, 'inventory'])->middleware('store.token:inventory:read')->name('store-api.inventory');
    Route::get('orders/{externalOrderId}', [StoreApiController::class, 'order'])->middleware('store.token:orders:read')->name('store-api.order');
});
