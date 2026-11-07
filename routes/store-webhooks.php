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
