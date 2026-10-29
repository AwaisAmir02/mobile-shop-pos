<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    if (! auth()->check()) {
        return view('site.home');
    }

    if (Auth::user()->is_super_admin) {
        return redirect()->route('admin.dashboard');
    }

    return redirect()->route(Auth::user()->hasAccessTo('dashboard') ? 'dashboard' : 'welcome');
})->name('home');

Route::view('features', 'site.features')->name('site.features');
Route::view('pricing', 'site.pricing')->name('site.pricing');
Route::view('about', 'site.about')->name('site.about');
Route::view('contact', 'site.contact')->name('site.contact');
Route::get('sitemap.xml', fn () => response()->view('site.sitemap')->header('Content-Type', 'application/xml'))->name('site.sitemap');
Route::view('signup', 'site.signup')->middleware(['guest', 'throttle:30,1'])->name('site.signup');
Route::view('signup/complete', 'site.signup-complete')->middleware(['guest', 'throttle:30,1'])->name('site.signup.complete');

Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/{provider}/redirect', [\App\Http\Controllers\Auth\SocialLoginController::class, 'redirect'])->whereIn('provider', ['google', 'microsoft'])->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [\App\Http\Controllers\Auth\SocialLoginController::class, 'callback'])->whereIn('provider', ['google', 'microsoft'])->name('oauth.callback');
});

Route::get('webhooks/whatsapp', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [\App\Http\Controllers\WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.receive')->middleware('throttle:600,1');

Route::middleware(['auth'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    Route::middleware('shop.user')->group(function () {
        Volt::route('welcome', 'welcome.index')->name('welcome');

        Volt::route('billing', 'billing.index')->name('billing.index');
        Route::get('billing/invoices/{invoice}/pdf', function (\App\Models\SubscriptionInvoice $invoice, \App\Services\SubscriptionInvoicePdfService $pdf) {
            abort_unless(auth()->user()->is_owner && $invoice->shop_id === auth()->user()->shop_id, 404);

            return $pdf->download($invoice);
        })->name('billing.invoices.pdf');

        Volt::route('dashboard', 'dashboard.index')->name('dashboard')->middleware('shop.access:dashboard');

        Volt::route('products', 'products.index')->name('products.index')->middleware('shop.access:products');

        Volt::route('stock-ins', 'stock-ins.create')->name('stock-ins.index')->middleware('shop.access:stock-ins');
        Volt::route('stock-ins/history', 'stock-ins.history')->name('stock-ins.history')->middleware('shop.access:stock-ins');

        Volt::route('vendors', 'vendors.index')->name('vendors.index')->middleware('shop.access:vendors');
        Volt::route('vendors/{vendor}', 'vendors.show')->name('vendors.show')->middleware('shop.access:vendors');

        Volt::route('customers', 'customers.index')->name('customers.index')->middleware('shop.access:customers');
        Volt::route('customers/{customer}', 'customers.show')->name('customers.show')->middleware('shop.access:customers');

        Volt::route('sales', 'sales.create')->name('sales.index')->middleware('shop.access:sales');
        Volt::route('sales/history', 'sales.history')->name('sales.history')->middleware('shop.access:sales');
        Volt::route('sales/{sale}', 'sales.show')->name('sales.show')->middleware('shop.access:sales');
        Volt::route('sales/{sale}/edit', 'sales.edit')->name('sales.edit')->middleware('shop.access:sales-edit-cancel');

        Volt::route('sim-sales', 'sim-sales.create')->name('sim-sales.index')->middleware('shop.access:sim-sales');
        Volt::route('sim-sales/history', 'sim-sales.history')->name('sim-sales.history')->middleware('shop.access:sim-sales');

        Volt::route('udhaar', 'udhaar.index')->name('udhaar.index')->middleware('shop.access:udhaar');
        Volt::route('udhaar/{customer}', 'udhaar.show')->name('udhaar.show')->middleware('shop.access:udhaar');

        Volt::route('balance-loads', 'balance-loads.create')->name('balance-loads.index')->middleware('shop.access:balance-loads');
        Volt::route('balance-loads/history', 'balance-loads.history')->name('balance-loads.history')->middleware('shop.access:balance-loads');

        Volt::route('wallet-loads', 'wallet-loads.create')->name('wallet-loads.index')->middleware('shop.access:wallet-loads');
        Volt::route('wallet-loads/history', 'wallet-loads.history')->name('wallet-loads.history')->middleware('shop.access:wallet-loads');

        Volt::route('bills', 'bills.create')->name('bills.index')->middleware('shop.access:bills');
        Volt::route('bills/history', 'bills.history')->name('bills.history')->middleware('shop.access:bills');

        Volt::route('repairs', 'repairs.create')->name('repairs.index')->middleware('shop.access:repairs');
        Volt::route('repairs/history', 'repairs.history')->name('repairs.history')->middleware('shop.access:repairs');

        Volt::route('services', 'services.create')->name('services.index')->middleware('shop.access:services');
        Volt::route('services/history', 'services.history')->name('services.history')->middleware('shop.access:services');

        Volt::route('whatsapp-orders', 'whatsapp-orders.index')->name('whatsapp-orders.index')->middleware('shop.access:whatsapp-orders');
        Volt::route('whatsapp-orders/{order}', 'whatsapp-orders.show')->name('whatsapp-orders.show')->middleware('shop.access:whatsapp-orders');
        Volt::route('customer-messages', 'whatsapp-messages.index')->name('whatsapp-messages.index')->middleware('shop.access:whatsapp-messages');

        Volt::route('marketing', 'marketing.index')->name('marketing.index')->middleware('shop.access:marketing-analytics');
        Volt::route('marketing/posts', 'marketing.posts')->name('marketing.posts')->middleware('shop.access:marketing');
        Volt::route('marketing/posts/{post}', 'marketing.post')->name('marketing.posts.show')->middleware('shop.access:marketing');
        Volt::route('marketing/reviews', 'marketing.reviews')->name('marketing.reviews')->middleware('shop.access:marketing');
        Volt::route('marketing/qr', 'marketing.qr')->name('marketing.qr')->middleware('shop.access:marketing');
        Volt::route('marketing/ads', 'marketing.ads')->name('marketing.ads')->middleware('shop.access:marketing-ads');
        Volt::route('marketing/ads/new', 'marketing.ad-create')->name('marketing.ads.create')->middleware('shop.access:marketing-ads');
        Volt::route('marketing/ads/{campaign}', 'marketing.ad')->name('marketing.ads.show')->middleware('shop.access:marketing-ads');
        Route::get('marketing/qr/{post}/{format}', function (\App\Models\MarketingPost $post, string $format, \App\Services\Marketing\QrCodeService $qrCodes) {
            $shop = auth()->user()->shop;
            abort_unless(($shop->whatsapp_enabled || $shop->social_enabled) && $post->kind === \App\Enums\PostKind::QrCode && in_array($format, ['png', 'pdf'], true), 404);
            $filename = \Illuminate\Support\Str::slug($post->title).'-qr.'.$format;

            if ($format === 'pdf') {
                return response($qrCodes->posterPdf($shop, $post), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
            }

            $link = $qrCodes->link($shop, $post);
            abort_if($link === null, 404);

            return response($qrCodes->png($link, 16), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
        })->name('marketing.qr.download')->middleware('shop.access:marketing');

        Volt::route('nadra-verifications', 'nadra-verifications.create')->name('nadra-verifications.index')->middleware('shop.access:nadra-verifications');
        Volt::route('nadra-verifications/history', 'nadra-verifications.history')->name('nadra-verifications.history')->middleware('shop.access:nadra-verifications');

        Volt::route('expenses', 'expenses.index')->name('expenses.index')->middleware('shop.access:expenses');

        Volt::route('reports', 'reports.index')->name('reports.index')->middleware('shop.access:reports');

        Volt::route('party-ledger', 'party-ledger.index')->name('party-ledger.index')->middleware('shop.access:party-ledger');

        Volt::route('settings', 'settings.index')->name('settings.index')->middleware('shop.access:settings,users,shop-accounts,settings-categories,settings-networks,settings-bill-config,settings-percentage,settings-brands,settings-branding,settings-services,settings-integrations');

        Volt::route('users', 'users.index')->name('users.index')->middleware('shop.access:users');
    });

    Route::middleware('super.admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('/', 'admin.dashboard')->name('dashboard');
        Volt::route('integrations', 'admin.integrations')->name('integrations');
        Volt::route('plans', 'admin.plans')->name('plans');
        Volt::route('addons', 'admin.addons')->name('addons');
        Volt::route('backups', 'admin.backups')->name('backups');
        Route::get('backups/{run}/download', function (\App\Models\BackupRun $run) {
            $local = \Illuminate\Support\Facades\Storage::disk(config('backup.local_disk'));

            if ($run->local_path && $local->exists($run->local_path)) {
                return $local->download($run->local_path, $run->archive_name);
            }

            abort_unless($run->remote_path, 404);

            return redirect()->away(\Illuminate\Support\Facades\Storage::disk(config('backup.remote_disk'))->temporaryUrl($run->remote_path, now()->addMinutes(10)));
        })->name('backups.download');
        Volt::route('payments', 'admin.payments')->name('payments');
        Route::get('payments/{payment}/proof', function (\App\Models\SubscriptionPayment $payment) {
            abort_unless($payment->proof_path && \Illuminate\Support\Facades\Storage::disk(\App\Services\Billing\SubscriptionPaymentService::PROOF_DISK)->exists($payment->proof_path), 404);

            return \Illuminate\Support\Facades\Storage::disk(\App\Services\Billing\SubscriptionPaymentService::PROOF_DISK)->response($payment->proof_path, null, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'"]);
        })->name('payments.proof');
        Volt::route('shops/create', 'admin.shops.create')->name('shops.create');
        Volt::route('shops/{shop}', 'admin.shops.show')->name('shops.show');

        Route::get('shops/{shop}/billing/invoices/{invoice}/pdf', function (\App\Models\Shop $shop, int $invoice, \App\Services\SubscriptionInvoicePdfService $pdf) {
            return $pdf->download(\App\Models\SubscriptionInvoice::where('shop_id', $shop->id)->findOrFail($invoice));
        })->name('shops.billing.invoices.pdf');
    });
});

require __DIR__.'/auth.php';
