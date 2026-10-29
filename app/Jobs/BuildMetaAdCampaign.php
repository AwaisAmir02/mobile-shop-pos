<?php

namespace App\Jobs;

use App\Models\MetaAdCampaign;
use App\Services\Marketing\MetaAdsManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildMetaAdCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(MetaAdsManager $ads): void
    {
        $campaign = MetaAdCampaign::withoutGlobalScopes()->find($this->campaignId);

        if ($campaign !== null) {
            $ads->build($campaign);
        }
    }
}
