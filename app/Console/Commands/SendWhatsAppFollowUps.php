<?php

namespace App\Console\Commands;

use App\Enums\WhatsAppOrderStatus;
use App\Models\Product;
use App\Models\RestockSubscription;
use App\Models\Shop;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\CustomerNotifications;
use Illuminate\Console\Command;
use Throwable;

class SendWhatsAppFollowUps extends Command
{
    protected $signature = 'whatsapp:follow-ups {--limit=50}';

    protected $description = 'Send due review requests and back-in-stock alerts';

    public const REVIEW_DELAY_HOURS = 24;

    public const REVIEW_WINDOW_DAYS = 7;

    public const RESTOCK_EXPIRY_DAYS = 60;

    public function handle(CustomerNotifications $notifications): int
    {
        $limit = (int) $this->option('limit');
        $enabledShopIds = Shop::where('whatsapp_enabled', true)->pluck('id');

        $reviews = 0;

        WhatsAppOrder::withoutGlobalScopes()
            ->whereIn('shop_id', $enabledShopIds)
            ->where('status', WhatsAppOrderStatus::Accepted->value)
            ->whereNotNull('delivered_at')
            ->whereNull('review_requested_at')
            ->whereBetween('delivered_at', [now()->subDays(self::REVIEW_WINDOW_DAYS), now()->subHours(self::REVIEW_DELAY_HOURS)])
            ->orderBy('delivered_at')
            ->limit($limit)
            ->get()
            ->each(function (WhatsAppOrder $order) use ($notifications, &$reviews) {
                $order->forceFill(['review_requested_at' => now()])->save();

                try {
                    $notifications->reviewRequest($order);
                    $reviews++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        RestockSubscription::withoutGlobalScopes()
            ->waiting()
            ->where('created_at', '<', now()->subDays(self::RESTOCK_EXPIRY_DAYS))
            ->update(['cancelled_at' => now()]);

        $restocks = 0;

        RestockSubscription::withoutGlobalScopes()
            ->waiting()
            ->whereIn('shop_id', $enabledShopIds)
            ->whereIn('product_id', Product::withoutGlobalScopes()->where('whatsapp_listed', true)->where('stock_quantity', '>', 0)->select('id'))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (RestockSubscription $subscription) use ($notifications, &$restocks) {
                $subscription->forceFill(['notified_at' => now()])->save();

                try {
                    $notifications->restock($subscription);
                    $restocks++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Sent {$reviews} review request(s) and {$restocks} restock alert(s).");

        return self::SUCCESS;
    }
}
