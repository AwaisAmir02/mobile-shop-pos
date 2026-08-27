<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopScreenAccess
{
    /**
     * Multiple screens may be passed (shop.access:settings,users) when a
     * route now serves content that used to live behind separate screens —
     * access is granted if the user has ANY of the listed screens.
     */
    public function handle(Request $request, Closure $next, string ...$screens): Response
    {
        $user = $request->user();

        abort_unless($user && collect($screens)->contains(fn (string $screen) => $user->hasAccessTo($screen)), 403);

        return $next($request);
    }
}
