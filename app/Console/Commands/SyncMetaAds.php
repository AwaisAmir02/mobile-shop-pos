<?php

namespace App\Console\Commands;

use App\Enums\AdCampaignStatus;
use App\Models\MetaAdCampaign;
use App\Models\Shop;
use App\Services\Marketing\MetaAdsManager;
use Illuminate\Console\Command;
use Throwable;

class SyncMetaAds extends Command
{
    protected $signature = 'marketing:sync-ads';

    protected $description = 'Refresh spend, results and review status of Meta ad campaigns';

    public const COMPLETED_LOOKBACK_DAYS = 3;

    public function handle(MetaAdsManager $ads): int
    {
        $synced = 0;

        MetaAdCampaign::withoutGlobalScopes()
            ->whereIn('shop_id', Shop::where('ads_enabled', true)->select('id'))
            ->where(function ($query) {
                $query->whereIn('status', [AdCampaignStatus::Active->value, AdCampaignStatus::Paused->value])
                    ->orWhere(fn ($q) => $q->whereIn('status', [AdCampaignStatus::Completed->value, AdCampaignStatus::Ended->value])
                        ->where('end_at', '>=', now()->subDays(self::COMPLETED_LOOKBACK_DAYS)));
            })
            ->orderBy('metrics_synced_at')
            ->limit(100)
            ->get()
            ->each(function (MetaAdCampaign $campaign) use ($ads, &$synced) {
                try {
                    $ads->sync($campaign);
                    $synced++;
                } catch (Throwable $e) {
                    $campaign->forceFill(['metrics_synced_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                }
            });

        $this->info("Synced {$synced} ad campaign(s).");

        return self::SUCCESS;
    }
}
