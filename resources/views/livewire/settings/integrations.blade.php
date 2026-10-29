<?php

use App\Enums\ChannelProvider;
use App\Enums\IntegrationProvider;
use App\Livewire\Concerns\Toasts;
use App\Models\PlatformIntegration;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Marketing\MetaAdsOnboarding;
use App\Services\Marketing\MetaOnboarding;
use App\Services\WhatsApp\TemplateLibrary;
use App\Services\WhatsApp\TemplateManager;
use App\Services\WhatsApp\WhatsAppOnboarding;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $manualPhoneNumberId = '';
    public string $manualWabaId = '';
    public string $manualToken = '';
    public string $manualDisplayName = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations'), 403);
    }

    protected function authorizeWhatsApp(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->whatsapp_enabled, 403);
    }

    public function completeWhatsAppSignup(string $code, string $phoneNumberId, string $wabaId, WhatsAppOnboarding $onboarding): void
    {
        $this->authorizeWhatsApp();

        try {
            $connection = $onboarding->completeEmbeddedSignup(Auth::user()->shop_id, Auth::id(), $code, $phoneNumberId, $wabaId);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp embedded signup failed', ['shop_id' => Auth::user()->shop_id, 'error' => $e->getMessage()]);
            $this->toastError('Could not connect WhatsApp: '.$e->getMessage());

            return;
        }

        $this->toastSuccess('WhatsApp connected: '.($connection->display_name ?? $phoneNumberId));
    }

    public function connectManually(WhatsAppOnboarding $onboarding): void
    {
        $this->authorizeWhatsApp();
        abort_unless(config('services.meta.allow_manual_connect'), 403);

        $this->validate([
            'manualPhoneNumberId' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
            'manualWabaId' => ['nullable', 'string', 'max:64', 'regex:/^\d+$/'],
            'manualToken' => ['required', 'string', 'max:1000'],
            'manualDisplayName' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $onboarding->attach(Auth::user()->shop_id, Auth::id(), $this->manualPhoneNumberId, $this->manualWabaId ?: null, $this->manualToken, $this->manualDisplayName ?: null);
        } catch (\RuntimeException $e) {
            $this->addError('manualPhoneNumberId', $e->getMessage());

            return;
        }

        $this->reset('manualPhoneNumberId', 'manualWabaId', 'manualToken', 'manualDisplayName');
        $this->toastSuccess('WhatsApp number attached.');
    }

    public function disconnectWhatsApp(WhatsAppOnboarding $onboarding): void
    {
        $this->authorizeWhatsApp();

        $connection = ShopChannelConnection::activeFor(Auth::user()->shop_id, ChannelProvider::WhatsApp);

        if ($connection !== null) {
            $onboarding->disconnect($connection);
        }

        $this->toastSuccess('WhatsApp disconnected. The chatbot will stop replying.');
    }

    public function submitTemplates(TemplateManager $templates): void
    {
        $this->authorizeWhatsApp();

        $connection = ShopChannelConnection::activeFor(Auth::user()->shop_id, ChannelProvider::WhatsApp);
        abort_if($connection === null, 404);

        try {
            $result = $templates->submitMissing($connection);
        } catch (\Throwable $e) {
            $this->toastError('Could not submit templates: '.$e->getMessage());

            return;
        }

        $result['failed'] === []
            ? $this->toastSuccess("{$result['submitted']} template(s) sent to Meta for approval.")
            : $this->toastError('Some templates failed: '.implode(' | ', $result['failed']));
    }

    public function refreshTemplates(TemplateManager $templates): void
    {
        $this->authorizeWhatsApp();

        $connection = ShopChannelConnection::activeFor(Auth::user()->shop_id, ChannelProvider::WhatsApp);
        abort_if($connection === null, 404);

        try {
            $templates->sync($connection);
        } catch (\Throwable $e) {
            $this->toastError('Could not refresh templates: '.$e->getMessage());

            return;
        }

        $this->toastSuccess('Template status refreshed.');
    }

    protected function authorizeSocial(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->social_enabled, 403);
    }

    public function completeMetaLogin(string $code, MetaOnboarding $onboarding): void
    {
        $this->authorizeSocial();

        try {
            $candidates = $onboarding->exchangeCode(Auth::id(), $code);
        } catch (\Throwable $e) {
            Log::warning('Meta login failed', ['shop_id' => Auth::user()->shop_id, 'error' => $e->getMessage()]);
            $this->toastError('Could not connect Facebook: '.$e->getMessage());

            return;
        }

        if (count($candidates) === 1) {
            $this->attachMetaPage($candidates[0]['id'], $onboarding);
        }
    }

    public function attachMetaPage(string $pageId, MetaOnboarding $onboarding): void
    {
        $this->authorizeSocial();

        try {
            $connections = $onboarding->attachPage(Auth::user()->shop_id, Auth::id(), $pageId);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(isset($connections['instagram'])
            ? 'Facebook Page and Instagram account connected.'
            : 'Facebook Page connected. No Instagram business account is linked to this Page.');
    }

    protected function authorizeAds(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->ads_enabled, 403);
    }

    public function completeAdsLogin(string $code, MetaAdsOnboarding $onboarding): void
    {
        $this->authorizeAds();

        try {
            $candidates = $onboarding->exchangeCode(Auth::id(), $code);
        } catch (\Throwable $e) {
            Log::warning('Meta ads login failed', ['shop_id' => Auth::user()->shop_id, 'error' => $e->getMessage()]);
            $this->toastError('Could not connect the ad account: '.$e->getMessage());

            return;
        }

        if (count($candidates) === 1) {
            $this->attachAdAccount($candidates[0]['id'], $onboarding);
        }
    }

    public function attachAdAccount(string $accountId, MetaAdsOnboarding $onboarding): void
    {
        $this->authorizeAds();

        try {
            $connection = $onboarding->attach(Auth::user()->shop_id, Auth::id(), $accountId);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess('Ad account '.$connection->display_name.' connected.');
    }

    public function disconnectAds(MetaAdsOnboarding $onboarding): void
    {
        $this->authorizeAds();
        $onboarding->disconnect(Auth::user()->shop_id);
        $this->toastSuccess('Ad account disconnected. Running ads keep running on Meta until you stop them.');
    }

    public function disconnectMeta(MetaOnboarding $onboarding): void
    {
        $this->authorizeSocial();
        $onboarding->disconnect(Auth::user()->shop_id);
        $this->toastSuccess('Facebook and Instagram disconnected.');
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $meta = PlatformIntegration::active(IntegrationProvider::Meta);
        $metaExtras = [
            'facebookConnection' => ShopChannelConnection::activeFor($shop->id, ChannelProvider::Facebook),
            'instagramConnection' => ShopChannelConnection::activeFor($shop->id, ChannelProvider::Instagram),
            'metaReady' => $meta !== null && $meta->configValue('login_config_id'),
            'metaAppId' => $meta?->app_id,
            'metaConfigId' => $meta?->configValue('login_config_id'),
            'pageCandidates' => app(MetaOnboarding::class)->candidates(Auth::id()),
            'adAccountConnection' => ShopChannelConnection::activeFor($shop->id, ChannelProvider::MetaAds),
            'adsLoginConfigId' => $meta?->configValue('ads_login_config_id'),
            'adAccountCandidates' => $shop->ads_enabled ? app(MetaAdsOnboarding::class)->candidates(Auth::id()) : [],
        ];
        $whatsapp = PlatformIntegration::active(IntegrationProvider::WhatsApp);

        return [
            'shop' => $shop,
            'whatsappConnection' => $whatsappConnection = ShopChannelConnection::activeFor($shop->id, ChannelProvider::WhatsApp),
            'templates' => $whatsappConnection
                ? WhatsAppTemplate::where('shop_channel_connection_id', $whatsappConnection->id)->orderBy('key')->orderBy('language')->get()
                : collect(),
            'whatsappReady' => $whatsapp !== null && $whatsapp->configValue('embedded_signup_config_id'),
            'whatsappAppId' => $whatsapp?->app_id,
            'whatsappConfigId' => $whatsapp?->configValue('embedded_signup_config_id'),
            'graphVersion' => config('services.meta.graph_version'),
            'allowManual' => (bool) config('services.meta.allow_manual_connect'),
        ] + $metaExtras;
    }
}; ?>

<div class="space-y-6">
    <x-ui.card title="WhatsApp" description="Connect your shop's WhatsApp Business number so customers can browse and order through a chatbot.">
        @if (! $shop->whatsapp_enabled)
            <p class="text-sm text-slate-500">WhatsApp ordering is not enabled for your shop. Contact HisabOKitab support to turn it on.</p>
        @elseif ($whatsappConnection)
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <x-ui.badge variant="success">Connected</x-ui.badge>
                        <span class="font-medium text-slate-900">{{ $whatsappConnection->display_name ?? $whatsappConnection->external_id }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Connected {{ $whatsappConnection->connected_at?->diffForHumans() }}.
                        Keep using the WhatsApp Business app on your phone as normal — open it at least once every 13 days to stay connected.
                    </p>
                    @if ($whatsappConnection->last_error)
                        <p class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">Last delivery problem: {{ $whatsappConnection->last_error }}</p>
                    @endif
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="disconnectWhatsApp" wire:confirm="Disconnect WhatsApp? The chatbot will stop replying to customers.">Disconnect</x-ui.button>
            </div>

            <div class="mt-6 border-t border-slate-100 pt-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-900">Message templates</p>
                        <p class="text-xs text-slate-500">WhatsApp needs pre-approved templates to message customers who haven't written to you in the last 24 hours (payment reminders, repair ready, order updates, back-in-stock, review requests).</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <x-ui.button size="sm" variant="secondary" wire:click="refreshTemplates">Refresh status</x-ui.button>
                        @if ($templates->count() < count(TemplateLibrary::definitions()) * 2 || $templates->contains('status', 'rejected'))
                            <x-ui.button size="sm" wire:click="submitTemplates">Submit templates</x-ui.button>
                        @endif
                    </div>
                </div>
                @if ($templates->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($templates as $template)
                            <x-ui.badge :variant="match ($template->status) { 'approved' => 'success', 'rejected' => 'danger', default => 'warning' }" title="{{ $template->rejection_reason }}">
                                {{ str_replace('_', ' ', $template->key) }} · {{ strtoupper($template->language) }} · {{ $template->status }}
                            </x-ui.badge>
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif (! $whatsappReady)
            <p class="text-sm text-slate-500">WhatsApp connection is not available yet. HisabOKitab is finishing setup with Meta — please check back soon.</p>
        @else
            <div
                x-data="{
                    phoneNumberId: null,
                    wabaId: null,
                    connecting: false,
                    init() {
                        window.addEventListener('message', (event) => {
                            if (! event.origin.endsWith('facebook.com')) return;
                            try {
                                const data = JSON.parse(event.data);
                                if (data.type === 'WA_EMBEDDED_SIGNUP' && data.data) {
                                    this.phoneNumberId = data.data.phone_number_id ?? this.phoneNumberId;
                                    this.wabaId = data.data.waba_id ?? this.wabaId;
                                }
                            } catch (e) {}
                        });
                        if (window.FB || document.getElementById('facebook-jssdk')) return;
                        const script = document.createElement('script');
                        script.id = 'facebook-jssdk';
                        script.src = 'https://connect.facebook.net/en_US/sdk.js';
                        script.async = true;
                        script.crossOrigin = 'anonymous';
                        document.body.appendChild(script);
                    },
                    connect() {
                        if (! window.FB) return;
                        FB.init({ appId: @js($whatsappAppId), autoLogAppEvents: true, xfbml: false, version: @js($graphVersion) });
                        this.connecting = true;
                        FB.login((response) => {
                            const code = response.authResponse?.code;
                            const finish = () => {
                                if (code && this.phoneNumberId && this.wabaId) {
                                    $wire.completeWhatsAppSignup(code, this.phoneNumberId, this.wabaId).then(() => this.connecting = false);
                                } else {
                                    this.connecting = false;
                                }
                            };
                            setTimeout(finish, 800);
                        }, {
                            config_id: @js($whatsappConfigId),
                            response_type: 'code',
                            override_default_response_type: true,
                            extras: { setup: {}, featureType: 'whatsapp_business_app_onboarding', sessionInfoVersion: '3' },
                        });
                    },
                }"
                class="space-y-3"
            >
                <p class="text-sm text-slate-600">
                    You'll sign in with Facebook and pick the WhatsApp Business number you already use on your phone.
                    Your chats stay in the WhatsApp Business app — HisabOKitab only adds the ordering chatbot.
                </p>
                <x-ui.button x-on:click="connect" x-bind:disabled="connecting">
                    <span x-show="! connecting">Connect WhatsApp</span>
                    <span x-show="connecting" x-cloak>Connecting…</span>
                </x-ui.button>
            </div>
        @endif

        @if ($shop->whatsapp_enabled && ! $whatsappConnection && $allowManual)
            <form wire:submit="connectManually" class="mt-6 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-2">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400 sm:col-span-2">Manual connection (testing only)</p>
                <x-ui.field label="Phone number ID" name="manualPhoneNumberId" for="manualPhoneNumberId">
                    <x-ui.input wire:model="manualPhoneNumberId" id="manualPhoneNumberId" />
                </x-ui.field>
                <x-ui.field label="WhatsApp Business Account ID" name="manualWabaId" for="manualWabaId">
                    <x-ui.input wire:model="manualWabaId" id="manualWabaId" />
                </x-ui.field>
                <x-ui.field label="Access token" name="manualToken" for="manualToken">
                    <x-ui.input wire:model="manualToken" id="manualToken" type="password" autocomplete="off" />
                </x-ui.field>
                <x-ui.field label="Display name" name="manualDisplayName" for="manualDisplayName">
                    <x-ui.input wire:model="manualDisplayName" id="manualDisplayName" />
                </x-ui.field>
                <div class="sm:col-span-2"><x-ui.button type="submit" variant="secondary" size="sm">Attach number</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card title="Facebook & Instagram" description="Connect your shop's Facebook Page (and the Instagram business account linked to it) to post products and stories from HisabOKitab.">
        @if (! $shop->social_enabled)
            <p class="text-sm text-slate-500">Social posting is not enabled for your shop. Contact HisabOKitab support to turn it on.</p>
        @elseif ($facebookConnection)
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1 text-sm">
                    <div class="flex items-center gap-2"><x-ui.badge variant="success">Connected</x-ui.badge><span class="font-medium text-slate-900">{{ $facebookConnection->display_name }}</span><span class="text-xs text-slate-500">Facebook Page</span></div>
                    @if ($instagramConnection)
                        <div class="flex items-center gap-2"><x-ui.badge variant="success">Connected</x-ui.badge><span class="font-medium text-slate-900">{{ $instagramConnection->display_name }}</span><span class="text-xs text-slate-500">Instagram</span></div>
                    @else
                        <p class="text-xs text-slate-500">No Instagram business account is linked to this Page. Link one in Instagram settings, then reconnect.</p>
                    @endif
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="disconnectMeta" wire:confirm="Disconnect Facebook and Instagram? Scheduled posts will fail until you reconnect.">Disconnect</x-ui.button>
            </div>
        @elseif (! $metaReady)
            <p class="text-sm text-slate-500">Facebook & Instagram connection is not available yet. HisabOKitab is finishing setup with Meta — please check back soon.</p>
        @elseif ($pageCandidates !== [])
            <p class="mb-3 text-sm text-slate-600">Which Page is your shop's?</p>
            <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($pageCandidates as $page)
                    <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="page-{{ $page['id'] }}">
                        <div>
                            <p class="font-medium text-slate-900">{{ $page['name'] }}</p>
                            <p class="text-xs text-slate-500">{{ $page['instagram_username'] ? 'Instagram: @'.$page['instagram_username'] : 'No Instagram linked' }}</p>
                        </div>
                        <x-ui.button size="sm" wire:click="attachMetaPage('{{ $page['id'] }}')">Use this Page</x-ui.button>
                    </div>
                @endforeach
            </div>
        @else
            <div
                x-data="{
                    connecting: false,
                    init() {
                        if (window.FB || document.getElementById('facebook-jssdk')) return;
                        const script = document.createElement('script');
                        script.id = 'facebook-jssdk';
                        script.src = 'https://connect.facebook.net/en_US/sdk.js';
                        script.async = true;
                        script.crossOrigin = 'anonymous';
                        document.body.appendChild(script);
                    },
                    connect() {
                        if (! window.FB) return;
                        FB.init({ appId: @js($metaAppId), autoLogAppEvents: true, xfbml: false, version: @js($graphVersion) });
                        this.connecting = true;
                        FB.login((response) => {
                            const code = response.authResponse?.code;
                            if (code) {
                                $wire.completeMetaLogin(code).then(() => this.connecting = false);
                            } else {
                                this.connecting = false;
                            }
                        }, { config_id: @js($metaConfigId), response_type: 'code', override_default_response_type: true });
                    },
                }"
                class="space-y-3"
            >
                <p class="text-sm text-slate-600">You'll sign in with Facebook and choose the Page you manage. We only post what you approve.</p>
                <x-ui.button x-on:click="connect" x-bind:disabled="connecting">
                    <span x-show="! connecting">Connect Facebook &amp; Instagram</span>
                    <span x-show="connecting" x-cloak>Connecting…</span>
                </x-ui.button>
            </div>
        @endif
    </x-ui.card>

    @if ($shop->ads_enabled)
        <x-ui.card title="Meta ad account" description="Needed to run paid ads. Meta bills this ad account directly with the payment method you added in Meta — HisabOKitab never charges for ad spend.">
            @if ($adAccountConnection)
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1 text-sm">
                        <div class="flex items-center gap-2"><x-ui.badge variant="success">Connected</x-ui.badge><span class="font-medium text-slate-900">{{ $adAccountConnection->display_name }}</span><span class="text-xs text-slate-500">{{ $adAccountConnection->meta['currency'] ?? '' }}</span></div>
                        @if ($adAccountConnection->token_expires_at && $adAccountConnection->token_expires_at->lt(now()->addDays(7)))
                            <p class="rounded-lg bg-amber-50 p-2 text-xs text-amber-800">Access expires {{ $adAccountConnection->token_expires_at->diffForHumans() }}. Reconnect to keep managing your ads.</p>
                        @endif
                    </div>
                    <x-ui.button variant="secondary" size="sm" wire:click="disconnectAds" wire:confirm="Disconnect the ad account? Ads already running keep running on Meta until you stop them there.">Disconnect</x-ui.button>
                </div>
            @elseif (! ($metaReady && $adsLoginConfigId))
                <p class="text-sm text-slate-500">Ad account connection is not available yet. HisabOKitab is finishing setup with Meta.</p>
            @elseif ($adAccountCandidates !== [])
                <p class="mb-3 text-sm text-slate-600">Which ad account should pay for your ads?</p>
                <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($adAccountCandidates as $account)
                        <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="adacct-{{ $account['id'] }}">
                            <div>
                                <p class="font-medium text-slate-900">{{ $account['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $account['currency'] }}{{ $account['business'] ? ' · '.$account['business'] : '' }}</p>
                            </div>
                            <x-ui.button size="sm" wire:click="attachAdAccount('{{ $account['id'] }}')">Use this account</x-ui.button>
                        </div>
                    @endforeach
                </div>
            @else
                <div
                    x-data="{
                        connecting: false,
                        init() {
                            if (window.FB || document.getElementById('facebook-jssdk')) return;
                            const script = document.createElement('script');
                            script.id = 'facebook-jssdk';
                            script.src = 'https://connect.facebook.net/en_US/sdk.js';
                            script.async = true;
                            script.crossOrigin = 'anonymous';
                            document.body.appendChild(script);
                        },
                        connect() {
                            if (! window.FB) return;
                            FB.init({ appId: @js($metaAppId), autoLogAppEvents: true, xfbml: false, version: @js($graphVersion) });
                            this.connecting = true;
                            FB.login((response) => {
                                const code = response.authResponse?.code;
                                if (code) {
                                    $wire.completeAdsLogin(code).then(() => this.connecting = false);
                                } else {
                                    this.connecting = false;
                                }
                            }, { config_id: @js($adsLoginConfigId), response_type: 'code', override_default_response_type: true });
                        },
                    }"
                >
                    <x-ui.button x-on:click="connect" x-bind:disabled="connecting">
                        <span x-show="! connecting">Connect ad account</span>
                        <span x-show="connecting" x-cloak>Connecting…</span>
                    </x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
