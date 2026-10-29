<?php

namespace App\Services\WhatsApp;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Models\PlatformIntegration;
use App\Models\ShopChannelConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppOnboarding
{
    public function completeEmbeddedSignup(int $shopId, int $userId, string $code, string $phoneNumberId, string $wabaId): ShopChannelConnection
    {
        $integration = PlatformIntegration::active(IntegrationProvider::WhatsApp)
            ?? throw new RuntimeException('WhatsApp is not configured by the administrator yet.');

        $token = Http::timeout(15)->get(WhatsAppClient::graphUrl('oauth/access_token'), [
            'client_id' => $integration->app_id,
            'client_secret' => $integration->app_secret,
            'code' => $code,
        ])->throw()->json('access_token');

        if (! $token) {
            throw new RuntimeException('Meta did not return an access token.');
        }

        Http::withToken($token)->timeout(15)->post(WhatsAppClient::graphUrl($wabaId.'/subscribed_apps'))->throw();

        $phone = Http::withToken($token)->timeout(15)->get(WhatsAppClient::graphUrl($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name',
        ])->throw()->json();

        return $this->attach($shopId, $userId, $phoneNumberId, $wabaId, $token, trim(($phone['verified_name'] ?? '').' '.($phone['display_phone_number'] ?? '')) ?: null, [
            'coexistence' => true,
            'display_phone_number' => $phone['display_phone_number'] ?? null,
        ]);
    }

    public function attach(int $shopId, int $userId, string $phoneNumberId, ?string $wabaId, string $token, ?string $displayName, array $meta = []): ShopChannelConnection
    {
        return DB::transaction(function () use ($shopId, $userId, $phoneNumberId, $wabaId, $token, $displayName, $meta) {
            $taken = ShopChannelConnection::withoutGlobalScopes()
                ->where('provider', ChannelProvider::WhatsApp->value)
                ->where('external_id', $phoneNumberId)
                ->where('shop_id', '!=', $shopId)
                ->where('status', ConnectionStatus::Connected->value)
                ->exists();

            if ($taken) {
                throw new RuntimeException('This WhatsApp number is already connected to another shop.');
            }

            ShopChannelConnection::withoutGlobalScopes()
                ->where('shop_id', $shopId)
                ->where('provider', ChannelProvider::WhatsApp->value)
                ->where('external_id', '!=', $phoneNumberId)
                ->update(['status' => ConnectionStatus::Revoked->value, 'access_token' => null]);

            $connection = ShopChannelConnection::withoutGlobalScopes()->firstOrNew([
                'provider' => ChannelProvider::WhatsApp->value,
                'external_id' => $phoneNumberId,
            ]);

            $connection->fill([
                'shop_id' => $shopId,
                'waba_id' => $wabaId,
                'display_name' => $displayName,
                'access_token' => $token,
                'meta' => $meta,
                'status' => ConnectionStatus::Connected,
                'last_error' => null,
                'connected_at' => now(),
                'connected_by' => $userId,
            ])->save();

            return $connection;
        });
    }

    public function disconnect(ShopChannelConnection $connection): void
    {
        $connection->forceFill([
            'status' => ConnectionStatus::Revoked,
            'access_token' => null,
        ])->save();
    }
}
