<?php

namespace App\Console\Commands;

use App\Enums\PostSurface;
use App\Models\MarketingPostTarget;
use App\Services\Marketing\MetaPublisher;
use Illuminate\Console\Command;
use Throwable;

class SyncMarketingInsights extends Command
{
    protected $signature = 'marketing:sync-insights {--stories-only}';

    protected $description = 'Refresh reach and engagement for published social posts';

    public const FEED_LOOKBACK_DAYS = 30;

    public function handle(MetaPublisher $publisher): int
    {
        $query = MarketingPostTarget::withoutGlobalScopes()->where('status', 'published');

        if ($this->option('stories-only')) {
            $query->where('surface', PostSurface::InstagramStory->value)
                ->where('published_at', '>=', now()->subHours(24));
        } else {
            $query->whereIn('surface', [PostSurface::FacebookFeed->value, PostSurface::InstagramFeed->value])
                ->where('published_at', '>=', now()->subDays(self::FEED_LOOKBACK_DAYS));
        }

        $synced = 0;

        $query->orderBy('metrics_synced_at')->limit(200)->get()->each(function (MarketingPostTarget $target) use ($publisher, &$synced) {
            try {
                $metrics = $publisher->insights($target);
            } catch (Throwable $e) {
                $target->forceFill(['metrics_synced_at' => now()])->save();

                return;
            }

            if ($metrics !== null) {
                $target->forceFill(['metrics' => array_merge($target->metrics ?? [], $metrics), 'metrics_synced_at' => now()])->save();
                $synced++;
            }
        });

        $this->info("Synced insights for {$synced} post(s).");

        return self::SUCCESS;
    }
}
