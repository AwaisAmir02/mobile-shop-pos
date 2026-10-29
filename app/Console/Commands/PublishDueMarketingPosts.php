<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\MarketingPost;
use App\Models\Shop;
use App\Services\Marketing\PostComposer;
use Illuminate\Console\Command;

class PublishDueMarketingPosts extends Command
{
    protected $signature = 'marketing:publish-due';

    protected $description = 'Start publishing scheduled social posts whose time has come';

    public function handle(PostComposer $composer): int
    {
        $socialShopIds = Shop::where('social_enabled', true)->pluck('id');

        $posts = MarketingPost::withoutGlobalScopes()
            ->whereIn('shop_id', $socialShopIds)
            ->where('status', PostStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        foreach ($posts as $post) {
            $post->update(['status' => PostStatus::Publishing]);
            $composer->dispatchTargets($post);
        }

        $this->info("Started {$posts->count()} scheduled post(s).");

        return self::SUCCESS;
    }
}
