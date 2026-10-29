<?php

use App\Enums\IntegrationProvider;
use App\Livewire\Concerns\Toasts;
use App\Models\PlatformIntegration;
use App\Models\WebhookEvent;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Integrations')] class extends Component
{
    use Toasts;

    public array $forms = [];

    public function mount(): void
    {
        foreach (IntegrationProvider::cases() as $provider) {
            $integration = PlatformIntegration::for($provider);

            $this->forms[$provider->value] = [
                'app_id' => $integration?->app_id ?? '',
                'app_secret' => '',
                'system_user_token' => '',
                'embedded_signup_config_id' => $integration?->configValue('embedded_signup_config_id') ?? '',
                'login_config_id' => $integration?->configValue('login_config_id') ?? '',
                'ads_login_config_id' => $integration?->configValue('ads_login_config_id') ?? '',
                'enabled' => (bool) $integration?->enabled,
            ];
        }
    }

    public function save(string $providerValue): void
    {
        $provider = IntegrationProvider::from($providerValue);
        $existing = PlatformIntegration::for($provider);
        $key = "forms.{$provider->value}";

        $this->validate([
            "$key.app_id" => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            "$key.app_secret" => [$existing?->app_secret ? 'nullable' : 'required', 'string', 'max:255'],
            "$key.system_user_token" => ['nullable', 'string', 'max:1000'],
            "$key.embedded_signup_config_id" => ['nullable', 'string', 'max:64'],
            "$key.login_config_id" => ['nullable', 'string', 'max:64'],
            "$key.ads_login_config_id" => ['nullable', 'string', 'max:64'],
            "$key.enabled" => ['boolean'],
        ], [], [
            "$key.app_id" => 'App ID',
            "$key.app_secret" => 'App secret',
        ]);

        $form = $this->forms[$provider->value];
        $integration = $existing ?? new PlatformIntegration(['provider' => $provider]);

        $integration->app_id = $form['app_id'];
        $integration->enabled = (bool) $form['enabled'];
        $integration->config = array_filter([
            'embedded_signup_config_id' => $form['embedded_signup_config_id'] ?: null,
            'login_config_id' => $form['login_config_id'] ?: null,
            'ads_login_config_id' => $form['ads_login_config_id'] ?: null,
        ]);

        if ($form['app_secret'] !== '') {
            $integration->app_secret = $form['app_secret'];
        }

        if ($form['system_user_token'] !== '') {
            $integration->system_user_token = $form['system_user_token'];
        }

        if (! $integration->webhook_verify_token) {
            $integration->webhook_verify_token = Str::random(40);
        }

        $integration->save();

        $this->forms[$provider->value]['app_secret'] = '';
        $this->forms[$provider->value]['system_user_token'] = '';

        $this->toastSuccess("{$provider->label()} settings saved.");
    }

    public function regenerateVerifyToken(string $providerValue): void
    {
        $integration = PlatformIntegration::for(IntegrationProvider::from($providerValue));

        if ($integration !== null) {
            $integration->update(['webhook_verify_token' => Str::random(40)]);
            $this->toastSuccess('Verify token regenerated — update it in the Meta app dashboard.');
        }
    }

    public function with(): array
    {
        return [
            'integrations' => collect(IntegrationProvider::cases())->mapWithKeys(fn ($p) => [$p->value => PlatformIntegration::for($p)]),
            'webhookUrls' => [
                'whatsapp' => route('webhooks.whatsapp.receive'),
                'meta' => null,
            ],
            'lastEvents' => collect(IntegrationProvider::cases())->mapWithKeys(fn ($p) => [
                $p->value => WebhookEvent::where('provider', $p->value)->latest('id')->first(),
            ]),
            'failedEvents' => WebhookEvent::whereNotNull('error')->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Integrations</h1>
    </x-slot>

    <p class="text-sm text-slate-500">Platform-wide Meta credentials. Shops never see these values — each shop connects its own WhatsApp number or Facebook Page through these apps.</p>

    @foreach (IntegrationProvider::cases() as $provider)
        @php($integration = $integrations[$provider->value])
        <x-ui.card :title="$provider->label()">
            <x-slot name="actions">
                @if ($integration?->enabled)
                    <x-ui.badge variant="success">Enabled</x-ui.badge>
                @else
                    <x-ui.badge>Disabled</x-ui.badge>
                @endif
            </x-slot>

            <form wire:submit="save('{{ $provider->value }}')" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Meta App ID" name="forms.{{ $provider->value }}.app_id" for="{{ $provider->value }}-app-id">
                    <x-ui.input wire:model="forms.{{ $provider->value }}.app_id" id="{{ $provider->value }}-app-id" />
                </x-ui.field>

                <x-ui.field label="App secret" name="forms.{{ $provider->value }}.app_secret" for="{{ $provider->value }}-secret" :help="$integration?->app_secret ? 'Saved — leave blank to keep the current secret.' : null">
                    <x-ui.input wire:model="forms.{{ $provider->value }}.app_secret" id="{{ $provider->value }}-secret" type="password" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="System user token (optional)" name="forms.{{ $provider->value }}.system_user_token" for="{{ $provider->value }}-sut" :help="$integration?->system_user_token ? 'Saved — leave blank to keep it.' : 'Used for platform-level calls such as template management.'">
                    <x-ui.input wire:model="forms.{{ $provider->value }}.system_user_token" id="{{ $provider->value }}-sut" type="password" autocomplete="off" />
                </x-ui.field>

                @if ($provider === IntegrationProvider::WhatsApp)
                    <x-ui.field label="Embedded Signup configuration ID" name="forms.whatsapp.embedded_signup_config_id" for="whatsapp-es-config">
                        <x-ui.input wire:model="forms.whatsapp.embedded_signup_config_id" id="whatsapp-es-config" />
                    </x-ui.field>
                @else
                    <x-ui.field label="Facebook Login for Business configuration ID" name="forms.meta.login_config_id" for="meta-login-config">
                        <x-ui.input wire:model="forms.meta.login_config_id" id="meta-login-config" />
                    </x-ui.field>
                    <x-ui.field label="Ads login configuration ID (ads_management, ads_read)" name="forms.meta.ads_login_config_id" for="meta-ads-login-config">
                        <x-ui.input wire:model="forms.meta.ads_login_config_id" id="meta-ads-login-config" />
                    </x-ui.field>
                @endif

                <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" wire:model="forms.{{ $provider->value }}.enabled" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Enabled
                </label>

                <div class="sm:col-span-2">
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>

            @if ($integration && $webhookUrls[$provider->value])
                <div class="mt-6 space-y-2 border-t border-slate-100 pt-5 text-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Webhook (paste into the Meta app dashboard)</p>
                    <div class="flex flex-col gap-1 sm:flex-row sm:gap-3"><span class="w-28 shrink-0 text-slate-500">Callback URL</span><code class="break-all text-slate-900">{{ $webhookUrls[$provider->value] }}</code></div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:gap-3">
                        <span class="w-28 shrink-0 text-slate-500">Verify token</span>
                        <code x-data="{ shown: false }" class="break-all text-slate-900">
                            <span x-show="shown" x-cloak>{{ $integration->webhook_verify_token }}</span>
                            <button type="button" x-show="! shown" x-on:click="shown = true" class="text-brand-700 hover:text-brand-800">Reveal</button>
                        </code>
                        <button type="button" wire:click="regenerateVerifyToken('{{ $provider->value }}')" wire:confirm="Regenerate the verify token? Meta must be updated with the new value." class="text-xs text-slate-500 hover:text-slate-700">Regenerate</button>
                    </div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:gap-3"><span class="w-28 shrink-0 text-slate-500">Subscribe to</span><span class="text-slate-900">messages, smb_message_echoes</span></div>
                    <div class="flex flex-col gap-1 sm:flex-row sm:gap-3"><span class="w-28 shrink-0 text-slate-500">Last event</span><span class="text-slate-900">{{ $lastEvents[$provider->value]?->received_at?->diffForHumans() ?? 'None received yet' }}</span></div>
                </div>
            @endif
        </x-ui.card>
    @endforeach

    @if ($failedEvents->isNotEmpty())
        <x-ui.card title="Recent webhook errors" :padding="false">
            <div class="divide-y divide-slate-100">
                @foreach ($failedEvents as $event)
                    <div class="px-5 py-3 text-sm">
                        <div class="flex justify-between gap-3"><span class="font-medium text-slate-900">{{ $event->event_key }}</span><span class="text-xs text-slate-500">{{ $event->received_at?->diffForHumans() }} · {{ $event->attempts }} attempt(s)</span></div>
                        <p class="mt-1 break-all text-xs text-red-700">{{ $event->error }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
