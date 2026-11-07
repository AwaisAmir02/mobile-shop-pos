<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Store\StoreApiController;
use App\Models\StoreConnection;
use App\Support\ApiShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves an event came from this connection's store, recently. An unknown connection
 * gets the same answer as a bad signature, so ids can't be probed. A new secret
 * replaces the old one, which keeps working only until its overlap ends.
 */
class VerifyStoreSignature
{
    public const WINDOW_SECONDS = 300;

    public const MAX_BYTES = 262144;

    public function handle(Request $request, Closure $next): Response
    {
        $connection = StoreConnection::withoutGlobalScopes()
            ->where('public_id', (string) $request->route('connection'))
            ->whereNull('removed_at')
            ->first();

        if ($connection === null || ! $this->signatureIsValid($request, $connection)) {
            return StoreApiController::error('invalid_signature', 'The signature is wrong, or the timestamp is outside the 5-minute window.', 401);
        }

        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return StoreApiController::error('validation_failed', 'That request is too large.', 422);
        }

        $request->attributes->set('storeConnection', $connection);

        return ApiShopContext::current()->run($connection->shop_id, fn () => $next($request));
    }

    protected function signatureIsValid(Request $request, StoreConnection $connection): bool
    {
        $timestamp = (string) $request->header('X-HOK-Timestamp');

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }

        $given = (string) $request->header('X-HOK-Signature');

        foreach ($this->secrets($connection) as $secret) {
            if (hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $given)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    protected function secrets(StoreConnection $connection): array
    {
        $previousStillValid = $connection->previous_signing_secret !== null && $connection->previous_secret_expires_at?->isFuture();

        return array_filter([$connection->signing_secret, $previousStillValid ? $connection->previous_signing_secret : null]);
    }
}
