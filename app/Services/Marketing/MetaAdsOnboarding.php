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

class MetaAdsOnboarding
{
    public const SCOPES = ['ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_read_engagement'];

    public const ACTIVE_ACCOUNT_STATUS = 1;

    protected function cacheKey(int $userId): string
    {
        return 'meta-ad-account-candidates:'.$userId;
    }

    public function exchangeCode(int $userId, string $code): array
    {
        $integration = PlatformIntegration::active(IntegrationProvider::Meta)
            ?? throw new RuntimeException('Meta is not configured by the administrator yet.');

        $shortLived = Http::timeout(15)->get(WhatsAppClient::graphUrl('oauth/access_token'), [
            'client_id' => $integration->app_id,
            'client_secret' => $integration->app_secret,
            'code' => $code,
        ])->throw()->json('access_token');

        $longLived = Http::timeout(15)->get(WhatsAppClient::graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $integration->app_id,
            'client_secret' => $integration->app_secret,
            'fb_exchange_token' => $shortLived,
        ])->throw()->json();

        $token = $longLived['access_token'] ?? $shortLived;
        $expiresIn = isset($longLived['expires_in']) ? (int) $longLived['expires_in'] : null;

        $accounts = Http::withToken($token)->timeout(15)->get(WhatsAppClient::graphUrl('me/adaccounts'), [
            'fields' => 'id,name,currency,account_status,min_daily_budget,timezone_name,business{id,name}',
            'limit' => 100,
        ])->throw()->json('data', []);

        $candidates = collect($accounts)
            ->filter(fn (array $account) => (int) ($account['account_status'] ?? 0) === self::ACTIVE_ACCOUNT_STATUS)
            ->map(fn (array $account) => [
                'id' => (string) $account['id'],
                'name' => $account['name'] ?? $account['id'],
                'currency' => $account['currency'] ?? 'PKR',
                'min_daily_budget' => isset($account['min_daily_budget']) ? (int) $account['min_daily_budget'] : null,
                'timezone' => $account['timezone_name'] ?? null,
                'business' => $account['business']['name'] ?? null,
                'token' => $token,
                'expires_in' => $expiresIn,
            ])
            ->values()
            ->all();

        if ($candidates === []) {
            throw new RuntimeException('No active ad account found. Create one in Meta Business Settings and add a payment method first.');
        }

        Cache::put($this->cacheKey($userId), Crypt::encrypt($candidates), now()->addMinutes(15));

        return $this->publicView($candidates);
    }

    public function candidates(int $userId): array
    {
        $payload = Cache::get($this->cacheKey($userId));

        return $payload ? $this->publicView(Crypt::decrypt($payload)) : [];
    }

    protected function publicView(array $candidates): array
    {
        return array_map(fn (array $c) => array_diff_key($c, ['token' => true, 'expires_in' => true]), $candidates);
    }

    public function attach(int $shopId, int $userId, string $accountId): ShopChannelConnection
    {
        $payload = Cache::get($this->cacheKey($userId));
        $account = $payload ? collect(Crypt::decrypt($payload))->firstWhere('id', $accountId) : null;

        if ($account === null) {
            throw new RuntimeException('That ad account selection has expired. Please connect again.');
        }

        $connection = DB::transaction(function () use ($shopId, $userId, $account) {
            $taken = ShopChannelConnection::withoutGlobalScopes()
                ->where('provider', ChannelProvider::MetaAds->value)
                ->where('external_id', $account['id'])
                ->where('shop_id', '!=', $shopId)
                ->where('status', ConnectionStatus::Connected->value)
                ->exists();

            if ($taken) {
                throw new RuntimeException('This ad account is already connected to another shop.');
            }

            ShopChannelConnection::withoutGlobalScopes()
                ->where('shop_id', $shopId)
                ->where('provider', ChannelProvider::MetaAds->value)
                ->where('external_id', '!=', $account['id'])
                ->update(['status' => ConnectionStatus::Revoked->value, 'access_token' => null]);

            $connection = ShopChannelConnection::withoutGlobalScopes()->firstOrNew([
                'provider' => ChannelProvider::MetaAds->value,
                'external_id' => $account['id'],
            ]);

            $connection->fill([
                'shop_id' => $shopId,
                'display_name' => $account['name'],
                'access_token' => $account['token'],
                'token_expires_at' => $account['expires_in'] ? now()->addSeconds($account['expires_in']) : null,
                'scopes' => self::SCOPES,
                'meta' => [
                    'currency' => $account['currency'],
                    'min_daily_budget' => $account['min_daily_budget'],
                    'timezone' => $account['timezone'],
                    'business' => $account['business'],
                ],
                'status' => ConnectionStatus::Connected,
                'last_error' => null,
                'connected_at' => now(),
                'connected_by' => $userId,
            ])->save();

            return $connection;
        });

        Cache::forget($this->cacheKey($userId));

        return $connection;
    }

    public function disconnect(int $shopId): void
    {
        ShopChannelConnection::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('provider', ChannelProvider::MetaAds->value)
            ->update(['status' => ConnectionStatus::Revoked->value, 'access_token' => null]);
    }
}
