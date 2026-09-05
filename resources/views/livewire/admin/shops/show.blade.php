<?php

use App\Enums\PlanType;
use App\Enums\ShopScreen;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
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

    public string $planType = 'monthly';
    public string $subscriptionStatus = 'inactive';
    public string $subscriptionStartDate = '';
    public string $productsAllowed = '';
    public string $salesAllowed = '';

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

        $this->planType = $shop->plan_type?->value ?? 'monthly';
        $this->subscriptionStatus = $shop->subscription_status->value;
        $this->subscriptionStartDate = $shop->subscription_start_date?->toDateString() ?? now()->toDateString();
        $this->productsAllowed = $shop->products_allowed !== null ? (string) $shop->products_allowed : '';
        $this->salesAllowed = $shop->sales_allowed !== null ? (string) $shop->sales_allowed : '';

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
        ]);

        $this->shop->update([
            'name' => $this->name,
            'phone' => $this->phone !== '' ? $this->phone : null,
            'email' => $this->email !== '' ? $this->email : null,
            'address' => $this->address !== '' ? $this->address : null,
        ]);

        $this->toastSuccess('Shop information updated.');
    }

    public function saveSubscription(): void
    {
        $this->validate([
            'planType' => ['required', Rule::enum(PlanType::class)],
            'subscriptionStatus' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscriptionStartDate' => ['required', 'date'],
            'productsAllowed' => ['nullable', 'integer', 'min:0'],
            'salesAllowed' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->shop->update([
            'plan_type' => $this->planType,
            'subscription_status' => $this->subscriptionStatus,
            'subscription_start_date' => $this->subscriptionStartDate,
            'products_allowed' => $this->productsAllowed !== '' ? $this->productsAllowed : null,
            'sales_allowed' => $this->salesAllowed !== '' ? $this->salesAllowed : null,
        ]);

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
        $this->toastSuccess("Password reset for {$user->email}.");
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
            'loginEmail' => $this->shop->user?->email,
            'expectedRenewalDate' => $this->shop->expectedRenewalDate(),
            'users' => $this->shop->users()->with('role')->orderByDesc('is_owner')->orderBy('name')->get(),
            'screens' => ShopScreen::cases(),
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

                        <x-ui.field label="Products Allowed" name="productsAllowed" for="productsAllowed" help="Reference only — not enforced">
                            <x-ui.input wire:model="productsAllowed" id="productsAllowed" type="number" min="0" />
                        </x-ui.field>

                        <x-ui.field label="Sales Allowed" name="salesAllowed" for="salesAllowed" help="Reference only — not enforced">
                            <x-ui.input wire:model="salesAllowed" id="salesAllowed" type="number" min="0" />
                        </x-ui.field>
                    </div>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="saveSubscription">
                            Save Subscription
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.stat label="Subscription Status" :value="$shop->isSubscriptionActive() ? 'Active' : 'Inactive'" />
            <x-ui.stat label="Product Count" :value="$productCount" />
            <x-ui.stat label="Customer Count" :value="$customerCount" />
            <x-ui.stat label="Udhaar Outstanding" value="Rs {{ number_format($udhaarOutstanding, 2) }}" sub="Current, across all customers" />
            <x-ui.stat label="Login Email" :value="$loginEmail ?? 'N/A'" />

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
        <x-ui.stat label="Total Wallet Loaded" value="Rs {{ number_format($totalWalletLoaded, 2) }}" />
        <x-ui.stat label="Total Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" />
        <x-ui.stat label="Stock-In Units" :value="number_format($totalStockInUnits)" />
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
                    <x-ui.table-cell>{{ $user->email }}</x-ui.table-cell>
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

    <x-ui.card title="Module Access" description="Turn a module off entirely for this shop — no one there, including the owner, can reach it while it's disabled here, regardless of their own role." class="mt-8">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($screens as $screen)
                @php $enabled = ! $shop->isScreenDisabled($screen); @endphp
                <div class="flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $screen->label() }}</p>
                        @if ($enabled)
                            <x-ui.badge variant="success">Enabled</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Disabled</x-ui.badge>
                        @endif
                    </div>
                    <x-ui.button
                        type="button"
                        size="sm"
                        variant="{{ $enabled ? 'secondary' : 'primary' }}"
                        wire:click="toggleScreen('{{ $screen->value }}')"
                        wire:confirm="{{ $enabled ? 'Disable' : 'Enable' }} {{ $screen->label() }} for this shop?"
                    >
                        {{ $enabled ? 'Disable' : 'Enable' }}
                    </x-ui.button>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
