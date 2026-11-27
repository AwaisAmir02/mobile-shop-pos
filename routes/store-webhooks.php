<?php

use App\Http\Controllers\Store\StoreWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Events from a shop's online store, docs/channel-api-v1.md. Stateless like the API,
 * so no session or CSRF. store.signature scopes the request to the connection's shop.
 */
Route::post('webhooks/channels/{connection}', [StoreWebhookController::class, 'receive'])
    ->middleware(['throttle:store-webhook', 'store.signature'])
    ->name('store-webhook.receive');

/*
 * Shopify and WooCommerce stores (O9), App\Http\Controllers\Store\StorefrontWebhookController.
 * Each request is checked against its platform's own signature before anything is read.
 */
Route::post('webhooks/shopify', [\App\Http\Controllers\Store\StorefrontWebhookController::class, 'shopify'])
    ->middleware('throttle:240,1')->name('storefronts.shopify.webhook');
Route::post('webhooks/woocommerce/{connection}', [\App\Http\Controllers\Store\StorefrontWebhookController::class, 'wooCommerce'])
    ->middleware('throttle:240,1')->name('storefronts.woocommerce.webhook');
Route::post('webhooks/woocommerce-keys', [\App\Http\Controllers\Store\StorefrontWebhookController::class, 'wooCommerceKeys'])
    ->middleware('throttle:30,1')->name('storefronts.woocommerce.keys');
