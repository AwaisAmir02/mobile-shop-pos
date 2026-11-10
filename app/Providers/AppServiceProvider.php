<?php

namespace App\Providers;

use App\Support\BranchContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Ledger\LedgerGate::class);
        $this->app->scoped(BranchContext::class);
        $this->app->scoped(\App\Support\ApiShopContext::class);
        $this->app->scoped(\App\Support\StoreChanges::class);
        $this->app->scoped(\App\Services\Billing\LimitNotices::class);
    }

    public function boot(): void
    {
        foreach (\App\Ledger\LedgerSources::observed() as $model) {
            $model::observe(\App\Ledger\LedgerObserver::class);
        }

        foreach ([\App\Models\Product::class, \App\Models\ProductGroup::class, \App\Models\StockIn::class, \App\Models\StoreProduct::class, \App\Models\StoreOrder::class, \App\Models\StoreOrderItem::class, \App\Models\WhatsAppOrder::class] as $model) {
            $model::observe(\App\Observers\StoreChangeObserver::class);
        }

        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // Per token, plus a wider cap per address so guessing tokens gets nowhere.
        RateLimiter::for('store-api', fn (Request $request) => [
            Limit::perMinute(300)->by('token:'.sha1((string) $request->bearerToken())),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('store-webhook', fn (Request $request) => [
            Limit::perMinute(300)->by('connection:'.$request->route('connection')),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
    }
}
