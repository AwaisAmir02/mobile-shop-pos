<?php

namespace App\Http\Middleware;

use App\Enums\OnlineTool;
use App\Enums\ServiceTradeTool;
use App\Enums\WholesaleTool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A screen behind one of a trade's tools (shop.tool:cheques, shop.tool:settlements):
 * not found unless the shop's trade uses it (BusinessCategory::wholesaleTools /
 * onlineTools / serviceTradeTools). Goes after shop.access, so a user without the
 * screen still gets 403 first. Tool values are unique across the three enums.
 */
class EnsureShopUsesTool
{
    public function handle(Request $request, Closure $next, string $tool): Response
    {
        abort_unless((bool) $request->user()?->shop?->usesTool(self::tool($tool)), 404);

        return $next($request);
    }

    public static function tool(string $value): WholesaleTool|OnlineTool|ServiceTradeTool
    {
        return WholesaleTool::tryFrom($value) ?? OnlineTool::tryFrom($value) ?? ServiceTradeTool::tryFrom($value)
            ?? throw new \InvalidArgumentException("No shop tool is called \"{$value}\".");
    }
}
