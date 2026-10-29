<?php

namespace App\Jobs;

use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Services\Marketing\MetaPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishMarketingPostTarget implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $targetId)
    {
    }

    public function handle(MetaPublisher $publisher): void
    {
        $claimed = MarketingPostTarget::withoutGlobalScopes()
            ->whereKey($this->targetId)
            ->where('status', 'pending')
            ->update(['status' => 'publishing']);

        if ($claimed === 0) {
            return;
        }

        $target = MarketingPostTarget::withoutGlobalScopes()->findOrFail($this->targetId);
        $target->increment('attempts');

        try {
            $result = $publisher->publish($target);

            $target->forceFill([
                'status' => 'published',
                'external_id' => $result['external_id'],
                'permalink' => $result['permalink'],
                'published_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $target->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        }

        MarketingPost::withoutGlobalScopes()->find($target->marketing_post_id)?->refreshStatusFromTargets();
    }
}
