<?php

use App\Enums\BusinessCategory;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\PlanType;
use App\Enums\ShopModule;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\Entitlements;
use App\Services\BusinessCategories;
use App\Services\ShopReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Shop Details')] class extends Component
{
    use Toasts;

    public Shop $shop;

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $businessCategory = '';

    public string $planId = '';
    public string $planType = 'monthly';
    public string $subscriptionStatus = 'inactive';
    public string $subscriptionStartDate = '';
    public bool $autoBilling = false;
    public string $extendDays = '7';
    /** @var array<string, string> per LimitKey value; blank = use the plan's limit */
    public array $limitOverrides = [];

    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    public string $periodType = 'day';
    public string $day = '';
    public string $month = '';

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;

        $this->name = $shop->name;
        $this->phone = (string) $shop->phone;
        $this->email = (string) $shop->email;
        $this->address = (string) $shop->address;
        $this->businessCategory = $shop->business_category?->value ?? '';

        $this->planId = $shop->plan_id !== null ? (string) $shop->plan_id : '';
        $this->planType = $shop->plan_type?->value ?? 'monthly';
        $this->subscriptionStatus = $shop->subscription_status->value;
        $this->subscriptionStartDate = $shop->subscription_start_date?->toDateString() ?? now()->toDateString();
        $this->autoBilling = (bool) $shop->auto_billing;
        $overrides = $shop->limit_overrides ?? [];
        foreach (LimitKey::cases() as $key) {
            $legacy = $key->legacyColumn();
            $this->limitOverrides[$key->value] = (string) ($overrides[$key->value] ?? ($legacy ? $shop->{$legacy} : null) ?? '');
        }

        $this->day = now()->toDateString();
        $this->month = now()->format('Y-m');
    }

    public function saveInfo(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'businessCategory' => ['nullable', Rule::enum(BusinessCategory::class)],
        ]);

        $this->shop->update([
            'name' => $this->name,
            'phone' => $this->phone !== '' ? $this->phone : null,
            'email' => $this->email !== '' ? $this->email : null,
            'address' => $this->address !== '' ? $this->address : null,
            'business_category' => $this->businessCategory !== '' ? $this->businessCategory : null,
        ]);

        $this->toastSuccess('Shop information updated.');
    }

    public function updatedPlanId(): void
    {
        $plan = $this->planId !== '' ? Plan::find($this->planId) : null;

        if ($plan) {
            $this->planType = $plan->billing_interval->value;
        }
    }

    public function saveSubscription(): void
    {
        $this->validate([
            'planId' => ['nullable', Rule::exists('plans', 'id')],
            'planType' => ['required', Rule::enum(PlanType::class)],
            'subscriptionStatus' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscriptionStartDate' => ['required', 'date'],
            ...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => ['limitOverrides.'.$key->value => ['nullable', 'integer', 'min:'.$key->minimum()]])->all(),
        ], [], collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => ['limitOverrides.'.$key->value => $key->label()])->all());

        $values = collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => (string) ($this->limitOverrides[$key->value] ?? '')]);
        $limitOverrides = $values->reject(fn (string $value) => $value === '')->map(fn (string $value) => (int) $value)->all();

        $this->shop->update([
            'limit_overrides' => $limitOverrides === [] ? null : $limitOverrides,
            'plan_id' => $this->planId !== '' ? (int) $this->planId : null,
            'plan_type' => $this->planType,
            'subscription_status' => $this->subscriptionStatus,
            'subscription_start_date' => $this->subscriptionStartDate,
            'auto_billing' => $this->autoBilling,
            ...collect(LimitKey::cases())->filter(fn (LimitKey $key) => $key->legacyColumn() !== null)
                ->mapWithKeys(fn (LimitKey $key) => [$key->legacyColumn() => $values[$key->value] !== '' ? $values[$key->value] : null])->all(),
        ]);

        $this->shop->refresh();
        app(Entitlements::class)->syncFeatureFlags($this->shop);
        app(Entitlements::class)->forgetMeters($this->shop);
        $this->shop->refresh();
        $this->toastSuccess('Subscription updated.');
    }

    /** More free days for a shop still on (or just past) its trial. */
    public function extendTrial(BillingCycle $cycle): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        $this->validate(['extendDays' => ['required', 'integer', 'min:1', 'max:365']], [], ['extendDays' => 'days']);

        try {
            $this->shop = $cycle->extendTrial($this->shop, (int) $this->extendDays);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess('Trial extended to '.$this->shop->trial_ends_at->format('d M Y, g:i a').'.');
    }

    public function renewSubscription(): void
    {
        $this->shop->update([
            'subscription_start_date' => now()->toDateString(),
            'subscription_status' => SubscriptionStatus::Active->value,
        ]);

        $this->shop->refresh();
        $this->subscriptionStartDate = $this->shop->subscription_start_date->toDateString();
        $this->subscriptionStatus = $this->shop->subscription_status->value;

        $this->toastSuccess('Subscription renewed.');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'newPassword' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->shop->user;

        abort_if(! $user, 404);

        $user->update(['password' => Hash::make($this->newPassword)]);

        $this->reset(['newPassword', 'newPassword_confirmation']);
        $this->toastSuccess("Password reset for {$user->loginLabel()}.");
    }

    public function setPeriodType(string $type): void
    {
        $this->periodType = $type;
    }

    public function toggleUserActive(int $userId): void
    {
        $user = $this->shop->users()->findOrFail($userId);

        $user->update(['is_active' => ! $user->is_active]);

        $this->toastSuccess($user->is_active ? "{$user->name} activated." : "{$user->name} deactivated.");
    }

    public function moduleOn(ShopModule $module): bool
    {
        return ! $this->shop->isScreenDisabled($module->mainScreen())
            && ($module->feature() === null || (bool) $this->shop->{$module->feature()->column()});
    }

    /**
     * The one switch for a module and everything in it. A module backed by a
     * feature is switched by that feature (following the plan unless it is
     * overridden here); any other is switched with disabled_screens. Either
     * way the module's own entries are cleared first, so a leftover from the
     * old per-screen switches can't keep part of it off.
     */
    public function toggleModule(string $module): void
    {
        $module = ShopModule::from($module);
        $turnOn = ! $this->moduleOn($module);

        $disabled = array_values(array_diff($this->shop->disabled_screens ?? [], $module->screenValues()));

        if (! $turnOn && $module->feature() === null) {
            $disabled[] = $module->value;
        }

        $this->shop->update(['disabled_screens' => $disabled]);

        if ($module->feature() !== null && (bool) $this->shop->{$module->feature()->column()} !== $turnOn) {
            $this->setFeature($module->feature(), $turnOn);
        }

        $this->shop->refresh();

        $this->toastSuccess($module->label().($turnOn ? ' turned on' : ' turned off').' for this shop.');
    }

    /**
     * A shop feature switch (shops.{feature}_enabled). Nothing in the shop's
     * own UI can flip them, only this page. Features with screens of their
     * own are switched through their module row (toggleModule).
     */
    public function toggleFeature(string $feature): void
    {
        $feature = FeatureKey::from($feature);
        $this->setFeature($feature, ! $this->shop->{$feature->column()});
        $this->shop->refresh();

        $this->toastSuccess($feature->adminSwitch()['toast'].($this->shop->{$feature->column()} ? ' enabled' : ' disabled').' for this shop.');
    }

    public function applyCategoryDefaults(BusinessCategories $categories): void
    {
        if (! $this->shop->business_category) {
            $this->toastError('Choose and save a business category first.');

            return;
        }

        $categories->applyDefaults($this->shop);
        $this->shop->refresh();

        $this->toastSuccess('Defaults for '.$this->shop->business_category->label().' applied.');
    }

    protected function setFeature(FeatureKey $feature, bool $value): void
    {
        $entitlements = app(Entitlements::class);

        if ($feature->planControlled() && $entitlements->governed($this->shop)) {
            $entitlements->setFeatureOverride($this->shop, $feature, $value);
        } else {
            $this->shop->update([$feature->column() => $value]);
        }
    }

    public function usePlanDefault(string $feature): void
    {
        app(Entitlements::class)->setFeatureOverride($this->shop, FeatureKey::from($feature), null);
        $this->shop->refresh();

        $this->toastSuccess(FeatureKey::from($feature)->label().' now follows the plan.');
    }

    protected function periodRange(): array
    {
        if ($this->periodType === 'month') {
            $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
        } else {
            $start = Carbon::parse($this->day)->startOfDay();
            $end = Carbon::parse($this->day)->endOfDay();
        }

        return [$start, $end];
    }

    protected function periodLabel(): string
    {
        [$start] = $this->periodRange();

        return $this->periodType === 'month' ? $start->format('F Y') : $start->format('d M Y');
    }

    public function with(ShopReportService $reports): array
    {
        [$start, $end] = $this->periodRange();

        $udhaarBalances = UdhaarTransaction::where('shop_id', $this->shop->id)
            ->selectRaw("SUM(
                CASE type
                    WHEN 'given' THEN amount
                    WHEN 'repayment_reversal' THEN amount
                    WHEN 'repayment' THEN -amount
                    WHEN 'given_reversal' THEN -amount
                    ELSE 0
                END
            ) as balance")
            ->value('balance');

        return [
            'periodLabel' => $this->periodLabel(),
            'productCount' => $this->shop->products()->count(),
            'customerCount' => $this->shop->customers()->count(),
            'udhaarOutstanding' => max(0.0, (float) $udhaarBalances),
            'loginEmail' => $this->shop->user?->loginLabel(),
            'expectedRenewalDate' => $this->shop->expectedRenewalDate(),
            'phase' => app(BillingCycle::class)->phase($this->shop),
            'cycleEndsAt' => app(BillingCycle::class)->endsAt($this->shop),
            'cycleDaysLeft' => app(BillingCycle::class)->daysLeft($this->shop),
            'locked' => app(BillingCycle::class)->locked($this->shop),
            'plans' => Plan::ordered()->get(),
            'governed' => app(Entitlements::class)->governed($this->shop),
            'hiddenModules' => BusinessCategories::hiddenModules($this->shop),
            'planLimits' => $this->shop->plan?->limits ?? [],
            'meters' => app(Entitlements::class)->meters($this->shop, fresh: true),
            'shopAddons' => $this->shop->addons()->latest('id')->limit(10)->get(),
            'featureOverrides' => collect(FeatureKey::cases())
                ->filter(fn (FeatureKey $feature) => app(Entitlements::class)->featureOverride($this->shop, $feature) !== null)
                ->map(fn (FeatureKey $feature) => [
                    'key' => $feature->value,
                    'label' => $feature->label(),
                    'value' => app(Entitlements::class)->featureOverride($this->shop, $feature),
                    'plan' => app(Entitlements::class)->planFeature($this->shop, $feature),
                ])->values(),
            'users' => $this->shop->users()->with('role')->orderByDesc('is_owner')->orderBy('name')->get(),
            'groupedModules' => ShopModule::grouped(),
            'otherFeatures' => collect(FeatureKey::adminSwitches())->filter(fn (FeatureKey $feature) => ShopModule::forFeature($feature) === null)->values()->all(),
            ...$reports->summary($this->shop, $start, $end),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('admin.dashboard') }}" back-label="Shops">
            {{ $shop->name }}
            <x-slot name="actions">
                <x-ui.button variant="secondary" size="sm" href="{{ route('admin.support', ['shop' => $shop->id, 'status' => 'all']) }}" wire:navigate>Support tickets</x-ui.button>
            </x-slot>
        </x-ui.page-header>
    </x-slot>

    {{-- Sections as tabs. Every panel is rendered as before (same forms and
         actions); only the visible one shows, so nothing typed is lost when
         switching. The tab is kept in the URL hash, so reloads and Livewire
         updates stay on it. --}}
    <div
        x-data="{
            tabs: ['overview', 'billing', 'activity', 'access'],
            tab: 'overview',
            init() {
                const hash = location.hash.slice(1);
                if (this.tabs.includes(hash)) this.tab = hash;
            },
            show(name) {
                this.tab = name;
                history.replaceState(null, '', '#' + name);
            },
            step(by) {
                const next = this.tabs[(this.tabs.indexOf(this.tab) + by + this.tabs.length) % this.tabs.length];
                this.show(next);
                this.$nextTick(() => document.getElementById('shop-tab-' + next).focus());
            },
        }"
        data-shop-tabs
    >
        <div class="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Shop sections">
            {{-- Short labels on phones so all four fit without scrolling. --}}
            @foreach (['overview' => ['Overview', 'Overview'], 'billing' => ['Subscription & Billing', 'Billing'], 'activity' => ['Activity', 'Activity'], 'access' => ['Team & Access', 'Team']] as $key => [$tabLabel, $shortLabel])
                <button
                    type="button"
                    role="tab"
                    id="shop-tab-{{ $key }}"
                    aria-controls="shop-panel-{{ $key }}"
                    :aria-selected="(tab === '{{ $key }}').toString()"
                    :tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-on:click="show('{{ $key }}')"
                    x-on:keydown.arrow-right.prevent="step(1)"
                    x-on:keydown.arrow-left.prevent="step(-1)"
                    class="relative shrink-0 whitespace-nowrap px-3.5 pb-3 pt-2 text-sm font-semibold transition-colors focus-visible:rounded-lg"
                    :class="tab === '{{ $key }}' ? 'text-on-primary-soft after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary' : 'text-ink-faint hover:text-ink'"
                >@if ($shortLabel !== $tabLabel)<span class="hidden sm:inline">{{ $tabLabel }}</span><span class="sm:hidden">{{ $shortLabel }}</span>@else{{ $tabLabel }}@endif</button>
            @endforeach
        </div>
        <div id="shop-panel-overview" role="tabpanel" aria-labelledby="shop-tab-overview" tabindex="0" x-show="tab === 'overview'" x-cloak>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <x-ui.card title="Shop Information">
                        <form wire:submit="saveInfo" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <x-ui.field label="Shop Name" name="name" for="name" class="sm:col-span-2">
                                    <x-ui.input wire:model="name" id="name" />
                                </x-ui.field>

                                <x-ui.field label="Phone" name="phone" for="phone">
                                    <x-ui.input wire:model="phone" id="phone" type="tel" />
                                </x-ui.field>

                                <x-ui.field label="Email" name="email" for="email">
                                    <x-ui.input wire:model="email" id="email" type="email" />
                                </x-ui.field>

                                <x-ui.field label="Address" name="address" for="address" class="sm:col-span-2">
                                    <x-ui.input wire:model="address" id="address" />
                                </x-ui.field>

                                <x-ui.field label="Business category" name="businessCategory" for="businessCategory" class="sm:col-span-2" help="Saving a new category does not change anything by itself — use “Apply category defaults” below.">
                                    <x-ui.select wire:model="businessCategory" id="businessCategory">
                                        <option value="">Not set — every module visible</option>
                                        @foreach (BusinessCategory::cases() as $option)
                                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </x-ui.field>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 text-xs text-ink-muted">
                                <span>
                                    Hidden by category / owner:
                                    <span class="font-medium text-ink">{{ $hiddenModules === [] ? 'nothing' : collect($hiddenModules)->map(fn ($key) => \App\Services\BusinessCategories::modules()[$key]['label'])->implode(', ') }}</span>
                                </span>
                                @if ($shop->business_category)
                                    <button type="button" wire:click="applyCategoryDefaults" wire:confirm="Apply the {{ $shop->business_category->label() }} defaults? Module visibility is reset to that category's defaults and any missing starter product categories are added. Nothing is deleted." class="font-semibold text-link hover:underline">Apply category defaults</button>
                                @endif
                            </div>

                            <div class="flex justify-end">
                                <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveInfo">
                                    Save Info
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                </div>

                <div class="space-y-6">
                <x-ui.stat label="Subscription Status" :value="$phase === \App\Enums\BillingPhase::Manual ? ($shop->isSubscriptionActive() ? 'Active' : 'Inactive') : $phase->label()" :sub="$phase === \App\Enums\BillingPhase::Manual ? null : ($locked ? 'Locked — the shop can log in but every screen asks for payment' : 'Automatic billing')" />
                <x-ui.stat label="Product Count" :value="$productCount" />
                <x-ui.stat label="Customer Count" :value="$customerCount" />
                <x-ui.stat label="Udhaar Outstanding" value="Rs {{ number_format($udhaarOutstanding, 2) }}" sub="Current, across all customers" />
                <x-ui.stat label="Login" :value="$loginEmail ?? 'N/A'" />

                <x-ui.card title="Reset Login Password" description="Sets a new password without needing the current one.">
                    <form wire:submit="resetPassword" class="space-y-4">
                        <x-ui.field label="New Password" name="newPassword" for="newPassword">
                            <x-ui.input wire:model="newPassword" id="newPassword" type="password" autocomplete="new-password" />
                        </x-ui.field>

                        <x-ui.field label="Confirm Password" name="newPassword_confirmation" for="newPassword_confirmation">
                            <x-ui.input wire:model="newPassword_confirmation" id="newPassword_confirmation" type="password" autocomplete="new-password" />
                        </x-ui.field>

                        <div class="flex justify-end">
                            <x-ui.button type="submit" size="sm" variant="secondary" wire:loading.attr="disabled" wire:target="resetPassword">
                                Reset Password
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
                </div>
            </div>
        </div>

        <div id="shop-panel-billing" role="tabpanel" aria-labelledby="shop-tab-billing" tabindex="0" x-show="tab === 'billing'" x-cloak>
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {{-- Full width when there are no meters to show beside it (shop not on a plan). --}}
                <div @class(['space-y-6', 'lg:col-span-2' => $meters !== [], 'lg:col-span-3' => $meters === []])>
                <x-ui.card title="Subscription">
                    <x-slot name="actions">
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="renewSubscription">
                            Renew Now
                        </x-ui.button>
                    </x-slot>

                    <form wire:submit="saveSubscription" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.field label="Plan" name="planId" for="planId" class="sm:col-span-2">
                                <x-ui.select wire:model.live="planId" id="planId">
                                    <option value="">No plan</option>
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->priceLabel() }} / {{ $plan->intervalLabel() }}{{ $plan->is_active ? '' : ' (inactive)' }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field label="Plan Type" name="planType" for="planType">
                                <x-ui.select wire:model="planType" id="planType">
                                    @foreach (PlanType::cases() as $plan)
                                        <option value="{{ $plan->value }}">{{ $plan->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field label="Status" name="subscriptionStatus" for="subscriptionStatus">
                                <x-ui.select wire:model="subscriptionStatus" id="subscriptionStatus">
                                    @foreach (SubscriptionStatus::cases() as $status)
                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>

                            <x-ui.field label="Start Date" name="subscriptionStartDate" for="subscriptionStartDate">
                                <x-ui.input wire:model="subscriptionStartDate" id="subscriptionStartDate" type="date" />
                            </x-ui.field>

                            <x-ui.field label="Expected Renewal">
                                <p class="py-2.5 text-sm text-ink-muted">
                                    {{ $expectedRenewalDate?->format('d M Y') ?? 'N/A' }}
                                </p>
                            </x-ui.field>

                        </div>

                        <div class="rounded-lg border border-line p-4" data-auto-billing>
                            <x-ui.checkbox wire:model="autoBilling" label="Automatic billing" />
                            <p class="mt-1 text-xs text-ink-faint">Free trial, a renewal invoice {{ config('billing.renewal_notice_days') }} days before each period ends, and every screen locked (login still works) while the trial or period has ended unpaid. Self-serve signups start on it. Off: billed by hand here, never locked. Turning it on locks the shop straight away if its renewal date above has passed.</p>
                            @if ($phase !== \App\Enums\BillingPhase::Manual)
                                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                    <x-ui.badge :variant="$phase->badge()">{{ $phase->label() }}</x-ui.badge>
                                    @if ($cycleEndsAt)
                                        <span class="text-ink-muted">
                                            {{ in_array($phase, [\App\Enums\BillingPhase::Trial, \App\Enums\BillingPhase::TrialEnded], true) ? 'Trial ends' : 'Period ends' }}
                                            {{ $cycleEndsAt->format('d M Y, g:i a') }}@if ($cycleDaysLeft > 0) · {{ $cycleDaysLeft }} {{ \Illuminate\Support\Str::plural('day', $cycleDaysLeft) }} left @endif
                                        </span>
                                    @endif
                                    @if ($phase->locks() && ! $locked)
                                        <span class="text-xs text-ink-faint">Open while a payment waits for review.</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="rounded-lg border border-line p-4">
                            <p class="text-sm font-medium text-ink">Usage limits</p>
                            <p class="mt-0.5 text-xs text-ink-faint">{{ $governed ? 'Leave blank to use the plan’s limit. A number here replaces it for this shop only.' : 'Not enforced — limits only apply to shops on a plan.' }}</p>
                            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                @foreach (LimitKey::cases() as $limitKey)
                                    <x-ui.field :label="$limitKey->label()" :name="'limitOverrides.'.$limitKey->value" :for="'limit-override-'.$limitKey->value" :help="$governed ? 'Plan: '.(array_key_exists($limitKey->value, $planLimits) && $planLimits[$limitKey->value] !== null ? number_format($planLimits[$limitKey->value]) : 'unlimited') : null">
                                        <x-ui.input wire:model="limitOverrides.{{ $limitKey->value }}" id="limit-override-{{ $limitKey->value }}" type="number" :min="$limitKey->minimum()" placeholder="Plan default" />
                                    </x-ui.field>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveSubscription">
                                Save Subscription
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>

                @if (in_array($phase, [\App\Enums\BillingPhase::Trial, \App\Enums\BillingPhase::TrialEnded], true))
                    <x-ui.card title="Extend trial" description="Gives this shop more free days, counted from the current trial end, or from now if it has already ended. An unpaid first invoice moves with it.">
                        <form wire:submit="extendTrial" class="flex flex-wrap items-end gap-3">
                            <x-ui.field label="Days to add" name="extendDays" for="extendDays">
                                <x-ui.input wire:model="extendDays" id="extendDays" type="number" min="1" max="365" class="w-32" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="extendTrial">Extend trial</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                    <livewire:admin.shops.billing :shop-id="$shop->id" />
                </div>

                @if ($meters !== [])
                <div class="space-y-6">
                    <x-ui.card title="Usage this month" description="Pakistan calendar month. Sales are never blocked — a shop over its sales limit is only flagged.">
                        <x-billing.usage-meters :meters="$meters" />
                        @if ($shopAddons->isNotEmpty())
                            <div class="mt-5 border-t border-line pt-4">
                                <p class="text-sm font-medium text-ink">Add-ons</p>
                                <ul class="mt-2 space-y-1.5 text-sm">
                                    @foreach ($shopAddons as $shopAddon)
                                        <li class="flex items-center justify-between gap-3">
                                            <span>{{ $shopAddon->name }}@if ($shopAddon->ends_at) <span class="text-xs text-ink-faint">· until {{ $shopAddon->ends_at->format('d M Y') }}</span>@endif</span>
                                            <x-ui.badge :variant="$shopAddon->status->badge()">{{ $shopAddon->status->label() }}</x-ui.badge>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </x-ui.card>
                </div>
                @endif
            </div>
        </div>

        <div id="shop-panel-activity" role="tabpanel" aria-labelledby="shop-tab-activity" tabindex="0" x-show="tab === 'activity'" x-cloak>
            <div class="mb-6 flex flex-wrap items-end gap-4">
                <div>
                    <x-ui.label value="Period" />
                    <div class="mt-1.5 inline-flex rounded-lg border border-line-strong p-1">
                        <button
                            type="button"
                            wire:click="setPeriodType('day')"
                            @class([
                                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                'bg-primary text-white' => $periodType === 'day',
                                'text-ink-muted hover:text-ink' => $periodType !== 'day',
                            ])
                        >
                            Day
                        </button>
                        <button
                            type="button"
                            wire:click="setPeriodType('month')"
                            @class([
                                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                'bg-primary text-white' => $periodType === 'month',
                                'text-ink-muted hover:text-ink' => $periodType !== 'month',
                            ])
                        >
                            Month
                        </button>
                    </div>
                </div>

                @if ($periodType === 'day')
                    <x-ui.field label="Date" name="day" for="day">
                        <x-ui.input wire:model.live="day" id="day" type="date" />
                    </x-ui.field>
                @else
                    <x-ui.field label="Month" name="month" for="month">
                        <x-ui.input wire:model.live="month" id="month" type="month" />
                    </x-ui.field>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.stat label="Total Sales Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" />
                <x-ui.stat label="Total Discount Given" value="Rs {{ number_format($totalDiscount, 2) }}" />
                <x-ui.stat label="Total Balance Loaded" value="Rs {{ number_format($totalBalanceLoaded, 2) }}" />
                <x-ui.stat label="Balance Load Fees" value="Rs {{ number_format($totalBalanceLoadFees, 2) }}" sub="Net service revenue" />
                <x-ui.stat label="Total Wallet Cash In" value="Rs {{ number_format($totalWalletCashInAmount, 2) }}" />
                <x-ui.stat label="Total Wallet Cash Out" value="Rs {{ number_format($totalWalletCashOutAmount, 2) }}" />
                <x-ui.stat label="Total Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" />
                <x-ui.stat label="Stock-In Units" :value="trim(number_format($totalStockInUnits).' '.(filled($stockInMeasured = \App\Enums\QuantityUnit::summarize($stockInByUnit ?? [])) ? '· '.$stockInMeasured : ''))" />
            </div>

            <div class="mt-4 rounded-xl border border-primary/15 bg-primary-soft px-5 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-link">Net (Revenue − Expenses)</p>
                        <p class="text-xs text-link">{{ $periodLabel }}</p>
                    </div>
                    <p class="text-display text-on-primary-soft">Rs {{ number_format($netSummary, 2) }}</p>
                </div>
            </div>
        </div>

        <div id="shop-panel-access" role="tabpanel" aria-labelledby="shop-tab-access" tabindex="0" x-show="tab === 'access'" x-cloak>
            <div class="space-y-6">
                <x-ui.card title="Team" description="Every user at this shop. Deactivating a user blocks them from logging in entirely, regardless of their role.">
                    <x-ui.table stack :headers="['Name', 'Email', 'Role', 'Status', '']">
                        @foreach ($users as $user)
                            <x-ui.table-row wire:key="shop-user-{{ $user->id }}">
                                <x-ui.table-cell primary class="font-medium text-ink">{{ $user->name }}</x-ui.table-cell>
                                <x-ui.table-cell label="Email">{{ $user->loginLabel() }}</x-ui.table-cell>
                                <x-ui.table-cell label="Role">
                                    @if ($user->is_owner)
                                        <x-ui.badge variant="brand">Owner</x-ui.badge>
                                    @else
                                        {{ $user->role?->name ?? 'No role' }}
                                    @endif
                                </x-ui.table-cell>
                                <x-ui.table-cell label="Status">
                                    @if ($user->is_active)
                                        <x-ui.badge variant="success">Active</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="danger">Deactivated</x-ui.badge>
                                    @endif
                                </x-ui.table-cell>
                                <x-ui.table-cell actions align="right">
                                    <x-ui.button
                                        size="sm"
                                        :variant="$user->is_active ? 'danger-ghost' : 'ghost'"
                                        wire:click="toggleUserActive({{ $user->id }})"
                                        wire:confirm="{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}?{{ $user->is_active ? ' They will be immediately blocked from logging in.' : '' }}"
                                    >
                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                    </x-ui.button>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>

                @php $importSwitch = FeatureKey::Import->adminSwitch(); @endphp
                <x-ui.card :title="$importSwitch['label']" :description="$importSwitch['description']">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-ink">
                            {{ $shop->import_enabled ? 'Enabled for this shop' : 'Disabled for this shop' }}
                        </span>
                        <button
                            type="button"
                            role="switch"
                            aria-checked="{{ $shop->import_enabled ? 'true' : 'false' }}"
                            aria-label="{{ $shop->import_enabled ? 'Disable' : 'Enable' }} {{ $importSwitch['label'] }}"
                            wire:click="toggleFeature('{{ FeatureKey::Import->value }}')"
                            wire:confirm="{{ $shop->import_enabled ? 'Disable' : 'Enable' }} {{ $importSwitch['label'] }} for this shop?"
                            @class([
                                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                'bg-success' => $shop->import_enabled,
                                'bg-line-strong' => ! $shop->import_enabled,
                            ])
                        >
                            <span
                                @class([
                                    'inline-block h-4 w-4 transform rounded-full bg-surface shadow transition-transform',
                                    'translate-x-6' => $shop->import_enabled,
                                    'translate-x-1' => ! $shop->import_enabled,
                                ])
                            ></span>
                        </button>
                    </div>
                </x-ui.card>

                <x-ui.card title="Module Access" description="One switch per module. Off means no one at this shop, the owner included, can reach any part of it, whatever their role allows.">
                    @if ($governed && $featureOverrides->isNotEmpty())
                        <div class="mb-6 rounded-lg bg-primary-soft/60 px-4 py-2.5 text-xs text-ink-muted">
                            Switches marked “Plan” follow the <span class="font-semibold">{{ $shop->plan?->name }}</span> plan. These differ from the plan for this shop only:
                            @foreach ($featureOverrides as $override)
                                <span class="mt-1 flex items-center justify-between gap-2">
                                    <span>{{ $override['label'] }}: <span class="font-semibold">{{ $override['value'] ? 'on' : 'off' }}</span> here, plan says {{ $override['plan'] === null ? 'nothing' : ($override['plan'] ? 'on' : 'off') }}</span>
                                    <button type="button" wire:click="usePlanDefault('{{ $override['key'] }}')" class="font-semibold text-link hover:underline">Use plan default</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        @foreach ($groupedModules as $groupLabel => $groupModules)
                            <div>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ $groupLabel }}</h3>
                                <div class="divide-y divide-line rounded-lg border border-line">
                                    @foreach ($groupModules as $module)
                                        @php
                                            $enabled = $this->moduleOn($module);
                                            $feature = $module->feature();
                                            $confirm = ($enabled ? 'Turn off ' : 'Turn on ').$module->label().' for this shop?'.($feature ? ' '.$feature->adminSwitch()['description'] : '');
                                        @endphp
                                        <div class="flex items-center justify-between gap-3 px-4 py-2.5" wire:key="module-{{ $module->value }}">
                                            <div class="min-w-0">
                                                <span class="text-sm font-medium text-ink">{{ $module->label() }}</span>
                                                @if ($feature && $governed && $feature->planControlled())
                                                    <x-ui.badge class="ml-1">Plan</x-ui.badge>
                                                @endif
                                                @if ($module->includesLabel())
                                                    <p class="text-xs text-ink-faint">{{ $module->includesLabel() }}</p>
                                                @endif
                                                @if ($shop->isScreenHidden($module->mainScreen()))
                                                    <p class="text-xs text-ink-faint">Hidden by the shop in Settings → Modules</p>
                                                @endif
                                            </div>
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked="{{ $enabled ? 'true' : 'false' }}"
                                                aria-label="{{ $enabled ? 'Turn off' : 'Turn on' }} {{ $module->label() }}"
                                                wire:click="toggleModule('{{ $module->value }}')"
                                                wire:confirm="{{ $confirm }}"
                                                @class([
                                                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                                    'bg-success' => $enabled,
                                                    'bg-line-strong' => ! $enabled,
                                                ])
                                            >
                                                <span
                                                    @class([
                                                        'inline-block h-4 w-4 transform rounded-full bg-surface shadow transition-transform',
                                                        'translate-x-6' => $enabled,
                                                        'translate-x-1' => ! $enabled,
                                                    ])
                                                ></span>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-faint">Other features</h3>
                            <p class="mb-2 text-xs text-ink-faint">Capabilities inside existing screens, with no screen of their own.</p>
                            <div class="divide-y divide-line rounded-lg border border-line">
                                @foreach ($otherFeatures as $feature)
                                    @php
                                        $switch = $feature->adminSwitch();
                                        $on = (bool) $shop->{$feature->column()};
                                    @endphp
                                    <div class="flex items-center justify-between gap-3 px-4 py-2.5" wire:key="feature-switch-{{ $feature->value }}">
                                        <div>
                                            <span class="text-sm font-medium text-ink">{{ $switch['label'] }}</span>
                                            @if ($governed && $feature->planControlled())
                                                <x-ui.badge class="ml-1">Plan</x-ui.badge>
                                            @endif
                                            <p class="text-xs text-ink-faint">{{ $switch['description'] }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked="{{ $on ? 'true' : 'false' }}"
                                            aria-label="{{ $on ? 'Disable' : 'Enable' }} {{ $switch['label'] }}"
                                            wire:click="toggleFeature('{{ $feature->value }}')"
                                            wire:confirm="{{ $on ? 'Disable' : 'Enable' }} {{ $switch['label'] }} for this shop?"
                                            @class([
                                                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                                'bg-success' => $on,
                                                'bg-line-strong' => ! $on,
                                            ])
                                        >
                                            <span
                                                @class([
                                                    'inline-block h-4 w-4 transform rounded-full bg-surface shadow transition-transform',
                                                    'translate-x-6' => $on,
                                                    'translate-x-1' => ! $on,
                                                ])
                                            ></span>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
