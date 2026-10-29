<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use Illuminate\Database\Eloquent\Model;

class PlatformIntegration extends Model
{
    protected $fillable = [
        'provider',
        'app_id',
        'app_secret',
        'system_user_token',
        'webhook_verify_token',
        'config',
        'enabled',
    ];

    protected $hidden = [
        'app_secret',
        'system_user_token',
        'webhook_verify_token',
    ];

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'app_secret' => 'encrypted',
            'system_user_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
        ];
    }

    public static function for(IntegrationProvider $provider): ?self
    {
        return static::where('provider', $provider->value)->first();
    }

    public static function active(IntegrationProvider $provider): ?self
    {
        $integration = static::for($provider);

        return $integration && $integration->enabled && $integration->app_id && $integration->app_secret
            ? $integration
            : null;
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
