<?php

namespace App\Providers;

use App\Support\BranchContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Livewire\Livewire;
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
        $this->app->scoped(\App\Services\LowStockAlerts::class);
    }

    public function boot(): void
    {
        // Stack traces must never reach visitors, even if APP_DEBUG is left on in production.
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
        }

        // Behind a CDN or proxy every visitor shares its address unless it's trusted, and per-IP limits collapse.
        if (filled($proxies = config('app.trusted_proxies'))) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        foreach (\App\Ledger\LedgerSources::observed() as $model) {
            $model::observe(\App\Ledger\LedgerObserver::class);
        }

        foreach ([\App\Models\Product::class, \App\Models\ProductGroup::class, \App\Models\StockIn::class, \App\Models\StoreProduct::class, \App\Models\StoreOrder::class, \App\Models\StoreOrderItem::class, \App\Models\WhatsAppOrder::class] as $model) {
            $model::observe(\App\Observers\StoreChangeObserver::class);
        }

        \App\Models\Product::observe(\App\Observers\LowStockObserver::class);
        Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn () => $this->app->make(\App\Services\LowStockAlerts::class)->rolledBack());

        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // Per token, plus a wider cap per address so guessing tokens gets nowhere.
        RateLimiter::for('store-api', fn (Request $request) => [
            Limit::perMinute(300)->by('token:'.sha1((string) $request->bearerToken())),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('site', fn (Request $request) => Limit::perMinute(300)->by('site:'.$request->ip()));

        // Every Livewire action posts here. Signed-in staff get a lot of room; guests only reach login, signup and password reset.
        RateLimiter::for('livewire', fn (Request $request) => $request->user()
            ? Limit::perMinute(600)->by('lw-user:'.$request->user()->id)
            : Limit::perMinute(120)->by('lw-ip:'.$request->ip()));

        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)->middleware(['web', 'throttle:livewire']));

        // Re-checked on every action, so access removed from a user stops working on pages they already have open.
        Livewire::addPersistentMiddleware([
            \App\Http\Middleware\EnsureSuperAdmin::class,
            \App\Http\Middleware\EnsureShopUser::class,
            \App\Http\Middleware\EnsureShopScreenAccess::class,
            \App\Http\Middleware\EnsureSubscriptionPaid::class,
        ]);

        RateLimiter::for('store-webhook', fn (Request $request) => [
            Limit::perMinute(300)->by('connection:'.$request->route('connection')),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
    }
}
