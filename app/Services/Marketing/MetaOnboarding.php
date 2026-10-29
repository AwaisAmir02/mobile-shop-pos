<?php

namespace App\Services\Marketing;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Models\PlatformIntegration;
use App\Models\ShopChannelConnection;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaOnboarding
{
    public const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'read_insights',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_insights',
        'business_management',
    ];

    protected function cacheKey(int $userId): string
    {
        return 'meta-page-candidates:'.$userId;
    }

    public function exchangeCode(int $userId, string $code): array
    {
        $integration = PlatformIntegration::active(IntegrationProvider::Meta)
            ?? throw new RuntimeException('Facebook & Instagram are not configured by the administrator yet.');

        $shortLived = Http::timeout(15)->get(WhatsAppClient::graphUrl('oauth/access_token'), [
            'client_id' => $integration->app_id,
            'client_secret' => $integration->app_secret,
            'code' => $code,
        ])->throw()->json('access_token');

        $userToken = Http::timeout(15)->get(WhatsAppClient::graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $integration->app_id,
            'client_secret' => $integration->app_secret,
            'fb_exchange_token' => $shortLived,
        ])->throw()->json('access_token') ?? $shortLived;

        $pages = Http::withToken($userToken)->timeout(15)->get(WhatsAppClient::graphUrl('me/accounts'), [
            'fields' => 'id,name,access_token,tasks,instagram_business_account{id,username}',
            'limit' => 100,
        ])->throw()->json('data', []);

        $candidates = collect($pages)
            ->filter(fn (array $page) => isset($page['access_token']) && in_array('CREATE_CONTENT', $page['tasks'] ?? ['CREATE_CONTENT'], true))
            ->map(fn (array $page) => [
                'id' => (string) $page['id'],
                'name' => $page['name'] ?? $page['id'],
                'token' => $page['access_token'],
                'instagram_id' => isset($page['instagram_business_account']['id']) ? (string) $page['instagram_business_account']['id'] : null,
                'instagram_username' => $page['instagram_business_account']['username'] ?? null,
            ])
            ->values()
            ->all();

        if ($candidates === []) {
            throw new RuntimeException('No Facebook Page found where you can create posts.');
        }

        Cache::put($this->cacheKey($userId), Crypt::encrypt($candidates), now()->addMinutes(15));

        return array_map(fn (array $c) => array_diff_key($c, ['token' => true]), $candidates);
    }

    public function candidates(int $userId): array
    {
        $payload = Cache::get($this->cacheKey($userId));

        return $payload ? array_map(fn (array $c) => array_diff_key($c, ['token' => true]), Crypt::decrypt($payload)) : [];
    }

    public function attachPage(int $shopId, int $userId, string $pageId): array
    {
        $payload = Cache::get($this->cacheKey($userId));
        $page = $payload ? collect(Crypt::decrypt($payload))->firstWhere('id', $pageId) : null;

        if ($page === null) {
            throw new RuntimeException('That page selection has expired. Please connect again.');
        }

        $connections = DB::transaction(function () use ($shopId, $userId, $page) {
            $this->revokeOthers($shopId, ChannelProvider::Facebook, $page['id']);
            $facebook = $this->upsert($shopId, $userId, ChannelProvider::Facebook, $page['id'], $page['name'], $page['token'], ['page_name' => $page['name']]);

            $instagram = null;

            if ($page['instagram_id']) {
                $this->revokeOthers($shopId, ChannelProvider::Instagram, $page['instagram_id']);
                $instagram = $this->upsert($shopId, $userId, ChannelProvider::Instagram, $page['instagram_id'], '@'.$page['instagram_username'], $page['token'], ['page_id' => $page['id'], 'username' => $page['instagram_username']]);
            } else {
                $this->revokeOthers($shopId, ChannelProvider::Instagram, null);
            }

            return array_filter(['facebook' => $facebook, 'instagram' => $instagram]);
        });

        Cache::forget($this->cacheKey($userId));

        return $connections;
    }

    public function disconnect(int $shopId): void
    {
        ShopChannelConnection::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->whereIn('provider', [ChannelProvider::Facebook->value, ChannelProvider::Instagram->value])
            ->update(['status' => ConnectionStatus::Revoked->value, 'access_token' => null]);
    }

    protected function revokeOthers(int $shopId, ChannelProvider $provider, ?string $keepExternalId): void
    {
        ShopChannelConnection::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('provider', $provider->value)
            ->when($keepExternalId, fn ($q) => $q->where('external_id', '!=', $keepExternalId))
            ->update(['status' => ConnectionStatus::Revoked->value, 'access_token' => null]);
    }

    protected function upsert(int $shopId, int $userId, ChannelProvider $provider, string $externalId, string $displayName, string $token, array $meta): ShopChannelConnection
    {
        $taken = ShopChannelConnection::withoutGlobalScopes()
            ->where('provider', $provider->value)
            ->where('external_id', $externalId)
            ->where('shop_id', '!=', $shopId)
            ->where('status', ConnectionStatus::Connected->value)
            ->exists();

        if ($taken) {
            throw new RuntimeException("This {$provider->label()} is already connected to another shop.");
        }

        $connection = ShopChannelConnection::withoutGlobalScopes()->firstOrNew([
            'provider' => $provider->value,
            'external_id' => $externalId,
        ]);

        $connection->fill([
            'shop_id' => $shopId,
            'display_name' => $displayName,
            'access_token' => $token,
            'scopes' => self::SCOPES,
            'meta' => $meta,
            'status' => ConnectionStatus::Connected,
            'last_error' => null,
            'connected_at' => now(),
            'connected_by' => $userId,
        ])->save();

        return $connection;
    }
}
