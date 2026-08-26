<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopScreenAccess
{
    public function handle(Request $request, Closure $next, string $screen): Response
    {
        abort_unless($request->user()?->hasAccessTo($screen), 403);

        return $next($request);
    }
}
