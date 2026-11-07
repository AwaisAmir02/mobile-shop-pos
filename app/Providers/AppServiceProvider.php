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
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Ledger\LedgerGate::class);
        $this->app->scoped(BranchContext::class);
        $this->app->scoped(\App\Support\ApiShopContext::class);
        $this->app->scoped(\App\Services\Billing\LimitNotices::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (\App\Ledger\LedgerSources::observed() as $model) {
            $model::observe(\App\Ledger\LedgerObserver::class);
        }

        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // Per token, plus a wider cap per address so guessing tokens gets nowhere.
        RateLimiter::for('store-api', fn (Request $request) => [
            Limit::perMinute(300)->by('token:'.sha1((string) $request->bearerToken())),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
    }
}
