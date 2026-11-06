<?php

use App\Enums\BusinessCategory;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\PlanType;
use App\Enums\ShopScreen;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
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
            ...collect(LimitKey::cases())->filter(fn (LimitKey $key) => $key->legacyColumn() !== null)
                ->mapWithKeys(fn (LimitKey $key) => [$key->legacyColumn() => $values[$key->value] !== '' ? $values[$key->value] : null])->all(),
        ]);

        $this->shop->refresh();
        app(Entitlements::class)->syncFeatureFlags($this->shop);
        app(Entitlements::class)->forgetMeters($this->shop);
        $this->shop->refresh();
        $this->toastSuccess('Subscription updated.');
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

    public function toggleScreen(string $screen): void
    {
        $disabled = $this->shop->disabled_screens ?? [];

        $disabled = in_array($screen, $disabled, true)
            ? array_values(array_diff($disabled, [$screen]))
            : [...$disabled, $screen];

        $this->shop->update(['disabled_screens' => $disabled]);
        $this->shop->refresh();

        $this->toastSuccess('Module access updated.');
    }

    /**
     * A shop feature switch (shops.{feature}_enabled). These are opt-in
     * capability flags, deliberately separate from Module Access: nothing
     * in the shop's own UI can flip them, only this page.
     */
    public function toggleFeature(string $feature): void
    {
        $feature = FeatureKey::from($feature);
        $this->flipFeature($feature);
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

    protected function flipFeature(FeatureKey $feature): void
    {
        $entitlements = app(Entitlements::class);
        $value = ! $this->shop->{$feature->column()};

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
            'screens' => ShopScreen::cases(),
            'groupedScreens' => ShopScreen::grouped(),
            ...$reports->summary($this->shop, $start, $end),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $shop->name }}</h1>
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All Shops
            </a>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
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

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <span>
                            Hidden by category / owner:
                            <span class="font-medium text-slate-800">{{ $hiddenModules === [] ? 'nothing' : collect($hiddenModules)->map(fn ($key) => \App\Services\BusinessCategories::modules()[$key]['label'])->implode(', ') }}</span>
                        </span>
                        @if ($shop->business_category)
                            <button type="button" wire:click="applyCategoryDefaults" wire:confirm="Apply the {{ $shop->business_category->label() }} defaults? Module visibility is reset to that category's defaults and any missing starter product categories are added. Nothing is deleted." class="font-semibold text-brand-700 hover:underline">Apply category defaults</button>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveInfo">
                            Save Info
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

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
                            <p class="py-2.5 text-sm text-slate-600">
                                {{ $expectedRenewalDate?->format('d M Y') ?? 'N/A' }}
                            </p>
                        </x-ui.field>

                    </div>

                    <div class="rounded-lg border border-slate-200 p-4">
                        <p class="text-sm font-medium text-slate-900">Usage limits</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $governed ? 'Leave blank to use the plan’s limit. A number here replaces it for this shop only.' : 'Not enforced — limits only apply to shops on a plan.' }}</p>
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

            @if ($meters !== [])
                <x-ui.card title="Usage this month" description="Pakistan calendar month. Sales are never blocked — a shop over its sales limit is only flagged.">
                    <x-billing.usage-meters :meters="$meters" />
                    @if ($shopAddons->isNotEmpty())
                        <div class="mt-5 border-t border-slate-100 pt-4">
                            <p class="text-sm font-medium text-slate-900">Add-ons</p>
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($shopAddons as $shopAddon)
                                    <li class="flex items-center justify-between gap-3">
                                        <span>{{ $shopAddon->name }}@if ($shopAddon->ends_at) <span class="text-xs text-slate-500">· until {{ $shopAddon->ends_at->format('d M Y') }}</span>@endif</span>
                                        <x-ui.badge :variant="$shopAddon->status->badge()">{{ $shopAddon->status->label() }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            <livewire:admin.shops.billing :shop-id="$shop->id" />
        </div>

        <div class="space-y-6">
            <x-ui.stat label="Subscription Status" :value="$shop->isSubscriptionActive() ? 'Active' : 'Inactive'" />
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

    <h2 class="mb-3 mt-8 text-sm font-semibold text-slate-700">Shop Activity</h2>

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <x-ui.label value="Period" />
            <div class="mt-1.5 inline-flex rounded-lg border border-slate-300 p-1">
                <button
                    type="button"
                    wire:click="setPeriodType('day')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-600 text-white' => $periodType === 'day',
                        'text-slate-600 hover:text-slate-900' => $periodType !== 'day',
                    ])
                >
                    Day
                </button>
                <button
                    type="button"
                    wire:click="setPeriodType('month')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-600 text-white' => $periodType === 'month',
                        'text-slate-600 hover:text-slate-900' => $periodType !== 'month',
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

    <div class="mt-4 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-brand-700">Net (Revenue − Expenses)</p>
                <p class="text-xs text-brand-600">{{ $periodLabel }}</p>
            </div>
            <p class="text-display text-brand-900">Rs {{ number_format($netSummary, 2) }}</p>
        </div>
    </div>

    <x-ui.card title="Team" description="Every user at this shop. Deactivating a user blocks them from logging in entirely, regardless of their role." class="mt-8">
        <x-ui.table :headers="['Name', 'Email', 'Role', 'Status', '']">
            @foreach ($users as $user)
                <x-ui.table-row wire:key="shop-user-{{ $user->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $user->name }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $user->loginLabel() }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($user->is_owner)
                            <x-ui.badge variant="brand">Owner</x-ui.badge>
                        @else
                            {{ $user->role?->name ?? 'No role' }}
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($user->is_active)
                            <x-ui.badge variant="success">Active</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Deactivated</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button
                            size="sm"
                            variant="ghost"
                            wire:click="toggleUserActive({{ $user->id }})"
                            wire:confirm="{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}?{{ $user->is_active ? ' They will be immediately blocked from logging in.' : '' }}"
                            class="{{ $user->is_active ? 'text-red-600 hover:bg-red-50' : '' }}"
                        >
                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    @php $importSwitch = FeatureKey::Import->adminSwitch(); @endphp
    <x-ui.card :title="$importSwitch['label']" :description="$importSwitch['description']" class="mt-8">
        <div class="flex items-center justify-between gap-3">
            <span class="text-sm font-medium text-slate-900">
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
                    'bg-emerald-500' => $shop->import_enabled,
                    'bg-slate-300' => ! $shop->import_enabled,
                ])
            >
                <span
                    @class([
                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                        'translate-x-6' => $shop->import_enabled,
                        'translate-x-1' => ! $shop->import_enabled,
                    ])
                ></span>
            </button>
        </div>
    </x-ui.card>

    <x-ui.card title="Module Access" description="Turn a module off entirely for this shop — no one there, including the owner, can reach it while it's disabled here, regardless of their own role." class="mt-8">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($groupedScreens as $groupLabel => $groupScreens)
                <div>
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $groupLabel }}</h3>
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                        @foreach ($groupScreens as $screen)
                            @php $enabled = ! $shop->isScreenDisabled($screen); @endphp
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="text-sm font-medium text-slate-900">{{ $screen->label() }}</span>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $enabled ? 'true' : 'false' }}"
                                    aria-label="{{ $enabled ? 'Disable' : 'Enable' }} {{ $screen->label() }}"
                                    wire:click="toggleScreen('{{ $screen->value }}')"
                                    wire:confirm="{{ $enabled ? 'Disable' : 'Enable' }} {{ $screen->label() }} for this shop?"
                                    @class([
                                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                        'bg-emerald-500' => $enabled,
                                        'bg-slate-300' => ! $enabled,
                                    ])
                                >
                                    <span
                                        @class([
                                            'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
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
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Shop Features</h3>
                <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @if ($governed)
                        <div class="bg-brand-50/60 px-4 py-2.5 text-xs text-slate-600">
                            These switches follow the <span class="font-semibold">{{ $shop->plan?->name }}</span> plan. Flipping one here overrides the plan for this shop only.
                            @foreach ($featureOverrides as $override)
                                <span class="mt-1 flex items-center justify-between gap-2">
                                    <span>{{ $override['label'] }}: <span class="font-semibold">{{ $override['value'] ? 'on' : 'off' }}</span> here, plan says {{ $override['plan'] === null ? 'nothing' : ($override['plan'] ? 'on' : 'off') }}</span>
                                    <button type="button" wire:click="usePlanDefault('{{ $override['key'] }}')" class="font-semibold text-brand-700 hover:underline">Use plan default</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @foreach (FeatureKey::adminSwitches() as $feature)
                        @php
                            $switch = $feature->adminSwitch();
                            $on = (bool) $shop->{$feature->column()};
                        @endphp
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5" wire:key="feature-switch-{{ $feature->value }}">
                            <div>
                                <span class="text-sm font-medium text-slate-900">{{ $switch['label'] }}</span>
                                <p class="text-xs text-slate-500">{{ $switch['description'] }}</p>
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
                                    'bg-emerald-500' => $on,
                                    'bg-slate-300' => ! $on,
                                ])
                            >
                                <span
                                    @class([
                                        'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
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
