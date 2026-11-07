<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Store\StoreApiController;
use App\Models\StoreApiToken;
use App\Support\ApiShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who's calling, and what they may see: the token picks the connection, and the
 * rest of the request runs scoped to that connection's shop. No scope named means
 * any valid token will do, even on a paused connection, so a store can ask why.
 */
class AuthenticateStoreToken
{
    /** Don't write to the database on every request just to note the time. */
    protected const LAST_USED_RESOLUTION_SECONDS = 60;

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $plain = (string) $request->bearerToken();

        $token = $plain === '' ? null : StoreApiToken::where('token_hash', StoreApiToken::hash($plain))
            ->whereNull('revoked_at')
            ->first();

        $connection = $token?->connection()->withoutGlobalScopes()->first();

        if ($token === null || $connection === null || $connection->removed_at !== null) {
            return StoreApiController::error('unauthenticated', 'The API token is missing, wrong or revoked.', 401);
        }

        foreach ($scopes as $scope) {
            if (! $token->allows($scope)) {
                return StoreApiController::error('insufficient_scope', "This token doesn't have the {$scope} scope.", 403);
            }
        }

        if ($scopes !== [] && ! $connection->isLive()) {
            return StoreApiController::error('connection_paused', 'This connection is paused. Stop selling until it resumes.', 403);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('storeConnection', $connection);

        return ApiShopContext::current()->run($connection->shop_id, fn () => $next($request));
    }
}
