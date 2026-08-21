<?php

use App\Enums\PlanType;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Create Shop')] class extends Component
{
    use Toasts;

    public string $shopName = '';
    public string $shopPhone = '';
    public string $shopEmail = '';
    public string $shopAddress = '';

    public string $userName = '';
    public string $userEmail = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $planType = 'monthly';
    public string $subscriptionStartDate = '';
    public string $productsAllowed = '';
    public string $salesAllowed = '';

    public function mount(): void
    {
        $this->subscriptionStartDate = now()->toDateString();
    }

    public function save(): void
    {
        $this->validate([
            'shopName' => ['required', 'string', 'max:255'],
            'shopPhone' => ['nullable', 'string', 'max:50'],
            'shopEmail' => ['nullable', 'email', 'max:255'],
            'shopAddress' => ['nullable', 'string', 'max:255'],

            'userName' => ['required', 'string', 'max:255'],
            'userEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'planType' => ['required', Rule::enum(PlanType::class)],
            'subscriptionStartDate' => ['required', 'date'],
            'productsAllowed' => ['nullable', 'integer', 'min:0'],
            'salesAllowed' => ['nullable', 'integer', 'min:0'],
        ]);

        $shop = DB::transaction(function () {
            $shop = Shop::create([
                'name' => $this->shopName,
                'phone' => $this->shopPhone !== '' ? $this->shopPhone : null,
                'email' => $this->shopEmail !== '' ? $this->shopEmail : null,
                'address' => $this->shopAddress !== '' ? $this->shopAddress : null,
                'plan_type' => $this->planType,
                'subscription_status' => SubscriptionStatus::Active->value,
                'subscription_start_date' => $this->subscriptionStartDate,
                'products_allowed' => $this->productsAllowed !== '' ? $this->productsAllowed : null,
                'sales_allowed' => $this->salesAllowed !== '' ? $this->salesAllowed : null,
            ]);

            $user = User::create([
                'shop_id' => $shop->id,
                'name' => $this->userName,
                'email' => $this->userEmail,
                'password' => Hash::make($this->password),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            return $shop;
        });

        $this->toastSuccess('Shop created.');
        $this->redirectRoute('admin.shops.show', $shop, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Create Shop</h1>
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">
                ← All Shops
            </a>
        </div>
    </x-slot>

    <form wire:submit="save" class="mx-auto max-w-3xl space-y-6">
        <x-ui.card title="Shop Information">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Shop Name" name="shopName" for="shopName" class="sm:col-span-2">
                    <x-ui.input wire:model="shopName" id="shopName" autofocus />
                </x-ui.field>

                <x-ui.field label="Phone" name="shopPhone" for="shopPhone" help="Optional">
                    <x-ui.input wire:model="shopPhone" id="shopPhone" type="tel" />
                </x-ui.field>

                <x-ui.field label="Email" name="shopEmail" for="shopEmail" help="Optional">
                    <x-ui.input wire:model="shopEmail" id="shopEmail" type="email" />
                </x-ui.field>

                <x-ui.field label="Address" name="shopAddress" for="shopAddress" class="sm:col-span-2" help="Optional">
                    <x-ui.input wire:model="shopAddress" id="shopAddress" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Login Credentials" description="The shop's single login — they cannot create additional users.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Owner Name" name="userName" for="userName">
                    <x-ui.input wire:model="userName" id="userName" />
                </x-ui.field>

                <x-ui.field label="Login Email" name="userEmail" for="userEmail">
                    <x-ui.input wire:model="userEmail" id="userEmail" type="email" />
                </x-ui.field>

                <x-ui.field label="Password" name="password" for="password">
                    <x-ui.input wire:model="password" id="password" type="password" autocomplete="new-password" />
                </x-ui.field>

                <x-ui.field label="Confirm Password" name="password_confirmation" for="password_confirmation">
                    <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Subscription">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Plan Type" name="planType" for="planType">
                    <x-ui.select wire:model="planType" id="planType">
                        @foreach (PlanType::cases() as $plan)
                            <option value="{{ $plan->value }}">{{ $plan->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Start Date" name="subscriptionStartDate" for="subscriptionStartDate">
                    <x-ui.input wire:model="subscriptionStartDate" id="subscriptionStartDate" type="date" />
                </x-ui.field>

                <x-ui.field label="Products Allowed" name="productsAllowed" for="productsAllowed" help="Reference only — not enforced">
                    <x-ui.input wire:model="productsAllowed" id="productsAllowed" type="number" min="0" />
                </x-ui.field>

                <x-ui.field label="Sales Allowed" name="salesAllowed" for="salesAllowed" help="Reference only — not enforced">
                    <x-ui.input wire:model="salesAllowed" id="salesAllowed" type="number" min="0" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.dashboard') }}" wire:navigate>
                <x-ui.button type="button" variant="secondary">Cancel</x-ui.button>
            </a>

            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                Create Shop
            </x-ui.button>
        </div>
    </form>
</div>
