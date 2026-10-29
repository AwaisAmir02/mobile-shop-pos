<?php

namespace App\Services\Marketing;

use App\Enums\AdCampaignStatus;
use App\Enums\AdGoal;
use App\Enums\ChannelProvider;
use App\Enums\PostSurface;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\MetaAdCampaign;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Services\WhatsApp\WhatsAppClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MetaAdsManager
{
    public const MIN_DAYS = 1;

    public const MAX_DAYS = 30;

    public const ZERO_DECIMAL_CURRENCIES = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];

    public const CONVERSATION_ACTION = 'onsite_conversion.messaging_conversation_started_7d';

    public static function minorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100));
    }

    public static function fromMinorUnits(int $amount, string $currency): float
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? (float) $amount : $amount / 100;
    }

    public static function maxDailyBudget(): float
    {
        return (float) config('services.meta.ads_max_daily_budget');
    }

    public static function minDailyBudget(ShopChannelConnection $adAccount): float
    {
        $minor = $adAccount->meta['min_daily_budget'] ?? null;

        return $minor ? self::fromMinorUnits((int) $minor, $adAccount->meta['currency'] ?? 'PKR') : 1.0;
    }

    public static function adAccount(int $shopId): ?ShopChannelConnection
    {
        return ShopChannelConnection::activeFor($shopId, ChannelProvider::MetaAds);
    }

    public static function whatsappStatusAvailable(int $shopId, AdGoal $goal): bool
    {
        return $goal === AdGoal::WhatsAppMessages
            && ShopChannelConnection::activeFor($shopId, ChannelProvider::Instagram) !== null;
    }

    public function createDraft(Shop $shop, int $userId, MarketingPost $post, AdGoal $goal, float $dailyBudget, CarbonInterface $startAt, CarbonInterface $endAt, array $targeting): MetaAdCampaign
    {
        if (! $shop->ads_enabled) {
            throw ValidationException::withMessages(['goal' => 'Meta ads are not enabled for this shop.']);
        }

        $adAccount = self::adAccount($shop->id)
            ?? throw ValidationException::withMessages(['goal' => 'Connect your Meta ad account in Settings → Integrations first.']);

        abort_unless($post->shop_id === $shop->id, 404);

        $errors = [];
        $min = self::minDailyBudget($adAccount);

        if ($dailyBudget < $min) {
            $errors['dailyBudget'] = 'Meta requires at least '.$adAccount->meta['currency'].' '.number_format($min, 2).' per day for this ad account.';
        } elseif ($dailyBudget > self::maxDailyBudget()) {
            $errors['dailyBudget'] = 'Daily budget is capped at '.number_format(self::maxDailyBudget()).' to prevent typos. Contact support for a higher limit.';
        }

        $days = $startAt->diffInMinutes($endAt) / 1440;

        if ($endAt->lte($startAt) || $days < self::MIN_DAYS || $days > self::MAX_DAYS + 0.01) {
            $errors['endDate'] = 'Ads must run between '.self::MIN_DAYS.' and '.self::MAX_DAYS.' days.';
        }

        if (ShopChannelConnection::activeFor($shop->id, ChannelProvider::Facebook) === null) {
            $errors['goal'] = 'Connect your Facebook Page first — Meta runs every ad from a Page.';
        } elseif ($goal === AdGoal::WhatsAppMessages && PostComposer::whatsappNumber($shop) === null) {
            $errors['goal'] = 'Connect WhatsApp (or add a shop phone number) to run WhatsApp message ads.';
        } elseif ($goal === AdGoal::PostEngagement && $this->publishedFacebookPost($post) === null) {
            $errors['goal'] = 'Publish this post to your Facebook Page first, then boost it.';
        } elseif ($goal === AdGoal::WhatsAppMessages && ! ($post->image_path && Storage::disk('public')->exists($post->image_path))) {
            $errors['goal'] = 'This post has no image to use in the ad.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $targeting['whatsapp_status'] = ! empty($targeting['whatsapp_status']) && self::whatsappStatusAvailable($shop->id, $goal);

        return MetaAdCampaign::create([
            'shop_id' => $shop->id,
            'created_by' => $userId,
            'shop_channel_connection_id' => $adAccount->id,
            'marketing_post_id' => $post->id,
            'goal' => $goal,
            'name' => mb_substr($shop->invoiceDisplayName().' · '.$post->title.' · '.$startAt->format('d M'), 0, 200),
            'status' => AdCampaignStatus::Draft,
            'currency' => $adAccount->meta['currency'] ?? 'PKR',
            'daily_budget' => $dailyBudget,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'targeting' => $targeting,
        ]);
    }

    protected function publishedFacebookPost(MarketingPost $post): ?MarketingPostTarget
    {
        return MarketingPostTarget::withoutGlobalScopes()
            ->where('marketing_post_id', $post->id)
            ->where('surface', PostSurface::FacebookFeed->value)
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->first();
    }

    protected function api(ShopChannelConnection $adAccount): PendingRequest
    {
        if (! $adAccount->access_token || ! $adAccount->isActive()) {
            throw new RuntimeException('The ad account is disconnected. Reconnect it in Settings → Integrations.');
        }

        return Http::withToken($adAccount->access_token)->acceptJson()->timeout(30);
    }

    protected function call(PendingRequest $api, string $method, string $path, array $data = []): array
    {
        $response = $api->{$method}(WhatsAppClient::graphUrl($path), $data);

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? 'Meta returned HTTP '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function targetingSpec(array $targeting): array
    {
        $geo = match ($targeting['mode'] ?? 'country') {
            'cities' => ['cities' => array_map(fn (array $city) => [
                'key' => (string) $city['key'],
                'radius' => (int) ($targeting['city_radius_km'] ?? 10),
                'distance_unit' => 'kilometer',
            ], $targeting['cities'] ?? [])],
            'radius' => ['custom_locations' => [[
                'latitude' => (float) $targeting['latitude'],
                'longitude' => (float) $targeting['longitude'],
                'radius' => (int) $targeting['radius_km'],
                'distance_unit' => 'kilometer',
            ]]],
            default => ['countries' => $targeting['countries'] ?? ['PK']],
        };

        $spec = [
            'geo_locations' => $geo,
            'age_min' => (int) ($targeting['age_min'] ?? 18),
            'age_max' => (int) ($targeting['age_max'] ?? 65),
            'publisher_platforms' => ['facebook', 'instagram'],
            'targeting_automation' => ['advantage_audience' => 0],
        ];

        if (! empty($targeting['whatsapp_status'])) {
            $spec['publisher_platforms'][] = 'whatsapp';
            $spec['whatsapp_positions'] = ['status'];
        }

        $gender = $targeting['gender'] ?? 'all';

        if ($gender !== 'all') {
            $spec['genders'] = [$gender === 'male' ? 1 : 2];
        }

        return $spec;
    }

    public function build(MetaAdCampaign $campaign): MetaAdCampaign
    {
        $campaign = MetaAdCampaign::withoutGlobalScopes()->findOrFail($campaign->id);

        if (! in_array($campaign->status, [AdCampaignStatus::Draft, AdCampaignStatus::Failed, AdCampaignStatus::Creating], true)) {
            return $campaign;
        }

        $campaign->forceFill(['status' => AdCampaignStatus::Creating, 'error' => null])->save();

        try {
            $adAccount = ShopChannelConnection::withoutGlobalScopes()->findOrFail($campaign->shop_channel_connection_id);
            $page = ShopChannelConnection::activeFor($campaign->shop_id, ChannelProvider::Facebook)
                ?? throw new RuntimeException('Your Facebook Page is not connected.');
            $instagram = ShopChannelConnection::activeFor($campaign->shop_id, ChannelProvider::Instagram);
            $post = MarketingPost::withoutGlobalScopes()->findOrFail($campaign->marketing_post_id);
            $shop = Shop::findOrFail($campaign->shop_id);
            $api = $this->api($adAccount);
            $account = $adAccount->external_id;

            if (! $campaign->meta_campaign_id) {
                $result = $this->call($api, 'post', $account.'/campaigns', [
                    'name' => $campaign->name,
                    'objective' => $campaign->goal->objective(),
                    'status' => 'PAUSED',
                    'special_ad_categories' => [],
                ]);
                $campaign->forceFill(['meta_campaign_id' => (string) $result['id']])->save();
            }

            if (! $campaign->meta_adset_id) {
                $adset = [
                    'name' => $campaign->name.' · Ad set',
                    'campaign_id' => $campaign->meta_campaign_id,
                    'daily_budget' => self::minorUnits((float) $campaign->daily_budget, $campaign->currency),
                    'billing_event' => 'IMPRESSIONS',
                    'optimization_goal' => $campaign->goal->optimizationGoal(),
                    'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                    'targeting' => $this->targetingSpec($campaign->targeting ?? []),
                    'start_time' => $campaign->start_at->toIso8601String(),
                    'end_time' => $campaign->end_at->toIso8601String(),
                    'status' => 'PAUSED',
                ];

                if ($campaign->goal === AdGoal::WhatsAppMessages) {
                    $adset['destination_type'] = 'WHATSAPP';
                    $adset['promoted_object'] = ['page_id' => $page->external_id, 'whatsapp_phone_number' => PostComposer::whatsappNumber($shop)];
                } else {
                    $adset['destination_type'] = 'ON_POST';
                    $adset['promoted_object'] = ['page_id' => $page->external_id];
                }

                $result = $this->call($api, 'post', $account.'/adsets', $adset);
                $campaign->forceFill(['meta_adset_id' => (string) $result['id']])->save();
            }

            if (! $campaign->meta_creative_id) {
                if ($campaign->goal === AdGoal::PostEngagement) {
                    $target = $this->publishedFacebookPost($post) ?? throw new RuntimeException('The Facebook post was not found.');
                    $creative = ['name' => $campaign->name.' · Creative', 'object_story_id' => $target->external_id];
                } else {
                    if (! $campaign->meta_image_hash) {
                        $upload = $this->call($api, 'post', $account.'/adimages', [
                            'bytes' => base64_encode(Storage::disk('public')->get($post->image_path)),
                        ]);
                        $hash = collect($upload['images'] ?? [])->first()['hash'] ?? throw new RuntimeException('Meta did not accept the ad image.');
                        $campaign->forceFill(['meta_image_hash' => $hash])->save();
                    }

                    $story = [
                        'page_id' => $page->external_id,
                        'link_data' => [
                            'image_hash' => $campaign->meta_image_hash,
                            'link' => 'https://api.whatsapp.com/send?phone='.PostComposer::whatsappNumber($shop),
                            'message' => mb_substr((string) $post->caption, 0, 1500),
                            'call_to_action' => ['type' => 'WHATSAPP_MESSAGE', 'value' => ['app_destination' => 'WHATSAPP']],
                        ],
                    ];

                    if ($instagram !== null) {
                        $story['instagram_user_id'] = $instagram->external_id;
                    }

                    $creative = ['name' => $campaign->name.' · Creative', 'object_story_spec' => $story];
                }

                $result = $this->call($api, 'post', $account.'/adcreatives', $creative);
                $campaign->forceFill(['meta_creative_id' => (string) $result['id']])->save();
            }

            if (! $campaign->meta_ad_id) {
                $result = $this->call($api, 'post', $account.'/ads', [
                    'name' => $campaign->name.' · Ad',
                    'adset_id' => $campaign->meta_adset_id,
                    'creative' => ['creative_id' => $campaign->meta_creative_id],
                    'status' => 'PAUSED',
                ]);
                $campaign->forceFill(['meta_ad_id' => (string) $result['id']])->save();
            }

            $campaign->forceFill(['status' => AdCampaignStatus::Ready])->save();
        } catch (\Throwable $e) {
            $campaign->forceFill(['status' => AdCampaignStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        return $campaign->fresh();
    }

    public function launch(MetaAdCampaign $campaign, int $userId, float $confirmedCap): MetaAdCampaign
    {
        return DB::transaction(function () use ($campaign, $userId, $confirmedCap) {
            $campaign = MetaAdCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== AdCampaignStatus::Ready || ! $campaign->isFullyCreated()) {
                throw ValidationException::withMessages(['launch' => 'This campaign is not ready to launch.']);
            }

            if (abs($confirmedCap - $campaign->maxSpend()) > 0.009) {
                throw ValidationException::withMessages(['launch' => 'Please confirm the maximum spend again — the budget changed.']);
            }

            if ($campaign->end_at->isPast()) {
                throw ValidationException::withMessages(['launch' => 'The end date has already passed. Create a new campaign.']);
            }

            $api = $this->api(ShopChannelConnection::withoutGlobalScopes()->findOrFail($campaign->shop_channel_connection_id));

            try {
                foreach ([$campaign->meta_ad_id, $campaign->meta_adset_id, $campaign->meta_campaign_id] as $objectId) {
                    $this->call($api, 'post', $objectId, ['status' => 'ACTIVE']);
                }
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['launch' => 'Meta refused to start the ad: '.$e->getMessage()]);
            }

            $campaign->forceFill([
                'status' => AdCampaignStatus::Active,
                'launched_by' => $userId,
                'launched_at' => now(),
                'spend_cap_confirmed' => $confirmedCap,
                'error' => null,
            ])->save();

            return $campaign;
        });
    }

    public function setRunning(MetaAdCampaign $campaign, bool $running): MetaAdCampaign
    {
        $expected = $running ? AdCampaignStatus::Paused : AdCampaignStatus::Active;

        if ($campaign->status !== $expected) {
            throw ValidationException::withMessages(['launch' => 'This campaign cannot be '.($running ? 'resumed' : 'paused').' right now.']);
        }

        if ($running && $campaign->end_at->isPast()) {
            throw ValidationException::withMessages(['launch' => 'The end date has already passed.']);
        }

        $api = $this->api(ShopChannelConnection::withoutGlobalScopes()->findOrFail($campaign->shop_channel_connection_id));

        try {
            $this->call($api, 'post', $campaign->meta_campaign_id, ['status' => $running ? 'ACTIVE' : 'PAUSED']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['launch' => $e->getMessage()]);
        }

        $campaign->forceFill(['status' => $running ? AdCampaignStatus::Active : AdCampaignStatus::Paused])->save();

        return $campaign;
    }

    public function end(MetaAdCampaign $campaign): MetaAdCampaign
    {
        if (! $campaign->status->isLive() && $campaign->status !== AdCampaignStatus::Ready) {
            throw ValidationException::withMessages(['launch' => 'This campaign is not running.']);
        }

        $api = $this->api(ShopChannelConnection::withoutGlobalScopes()->findOrFail($campaign->shop_channel_connection_id));

        try {
            $this->call($api, 'post', $campaign->meta_campaign_id, ['status' => 'ARCHIVED']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['launch' => $e->getMessage()]);
        }

        $campaign->forceFill(['status' => AdCampaignStatus::Ended])->save();

        return $campaign;
    }

    public function discard(MetaAdCampaign $campaign): void
    {
        if (! in_array($campaign->status, [AdCampaignStatus::Draft, AdCampaignStatus::Failed, AdCampaignStatus::Ready], true)) {
            throw ValidationException::withMessages(['launch' => 'Only campaigns that never ran can be discarded. Stop it instead.']);
        }

        if ($campaign->meta_campaign_id) {
            $adAccount = ShopChannelConnection::withoutGlobalScopes()->find($campaign->shop_channel_connection_id);

            try {
                $this->call($this->api($adAccount), 'post', $campaign->meta_campaign_id, ['status' => 'DELETED']);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['launch' => 'Could not remove it from Meta: '.$e->getMessage()]);
            }
        }

        $campaign->delete();
    }

    public function sync(MetaAdCampaign $campaign): MetaAdCampaign
    {
        $adAccount = ShopChannelConnection::withoutGlobalScopes()->find($campaign->shop_channel_connection_id);

        if ($adAccount === null || ! $campaign->meta_campaign_id) {
            return $campaign;
        }

        $api = $this->api($adAccount);
        $status = $this->call($api, 'get', $campaign->meta_campaign_id, ['fields' => 'effective_status'])['effective_status'] ?? null;

        $review = null;

        if ($campaign->meta_ad_id) {
            $ad = $this->call($api, 'get', $campaign->meta_ad_id, ['fields' => 'effective_status,ad_review_feedback']);
            $feedback = $ad['ad_review_feedback'] ?? null;
            $review = $feedback ? collect($feedback)->flatten()->filter(fn ($v) => is_string($v))->implode(' ') : null;
            $status = in_array($ad['effective_status'] ?? null, ['DISAPPROVED', 'PENDING_REVIEW', 'WITH_ISSUES'], true) ? $ad['effective_status'] : $status;
        }

        $insights = $this->call($api, 'get', $campaign->meta_campaign_id.'/insights', [
            'fields' => 'spend,impressions,reach,clicks,actions',
            'date_preset' => 'maximum',
        ])['data'][0] ?? [];

        $actions = collect($insights['actions'] ?? [])->pluck('value', 'action_type');

        $campaign->forceFill([
            'effective_status' => $status,
            'review_feedback' => $review,
            'metrics' => [
                'spend' => (float) ($insights['spend'] ?? 0),
                'impressions' => (int) ($insights['impressions'] ?? 0),
                'reach' => (int) ($insights['reach'] ?? 0),
                'clicks' => (int) ($insights['clicks'] ?? 0),
                'conversations' => (int) ($actions[self::CONVERSATION_ACTION] ?? 0),
                'engagement' => (int) ($actions['post_engagement'] ?? 0),
            ],
            'metrics_synced_at' => now(),
            'status' => $campaign->status === AdCampaignStatus::Active && $campaign->end_at->isPast() ? AdCampaignStatus::Completed : $campaign->status,
        ])->save();

        return $campaign;
    }

    public function searchCities(ShopChannelConnection $adAccount, string $query, string $countryCode = 'PK'): array
    {
        $results = $this->call($this->api($adAccount), 'get', 'search', [
            'type' => 'adgeolocation',
            'location_types' => json_encode(['city']),
            'country_code' => $countryCode,
            'q' => $query,
            'limit' => 8,
        ])['data'] ?? [];

        return array_map(fn (array $city) => [
            'key' => (string) $city['key'],
            'name' => $city['name'].(isset($city['region']) ? ', '.$city['region'] : ''),
        ], $results);
    }
}
