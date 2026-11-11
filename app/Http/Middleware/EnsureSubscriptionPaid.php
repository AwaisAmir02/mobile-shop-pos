<?php

namespace App\Http\Middleware;

use App\Services\Billing\BillingCycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop whose trial or paid period has ended unpaid can still log in, but
 * every screen behind this sends it to pay: the owner to Plan & Usage, staff
 * to a page saying the owner needs to. Also a Livewire persistent
 * middleware, so actions on a page already open stop too (Livewire follows
 * the redirect with a full page load).
 */
class EnsureSubscriptionPaid
{
    public function __construct(protected BillingCycle $cycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin || ! $user->shop || ! $this->cycle->locked($user->shop)) {
            return $next($request);
        }

        return redirect()->route($user->is_owner ? 'billing.index' : 'billing.locked');
    }
}
