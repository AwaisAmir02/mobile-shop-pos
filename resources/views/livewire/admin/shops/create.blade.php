<?php

use App\Actions\CreateShopWithOwner;
use App\Enums\BusinessCategory;
use App\Enums\PlanType;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\Toasts;
use Illuminate\Validation\Rule;
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
    public string $businessCategory = '';

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
        $shopRules = CreateShopWithOwner::shopRules();
        $ownerRules = CreateShopWithOwner::ownerRules();

        $this->validate([
            'shopName' => $shopRules['name'],
            'shopPhone' => $shopRules['phone'],
            'shopEmail' => $shopRules['email'],
            'shopAddress' => $shopRules['address'],
            'businessCategory' => $shopRules['business_category'],

            'userName' => $ownerRules['name'],
            'userEmail' => $ownerRules['email'],
            'password' => $ownerRules['password'],

            'planType' => ['required', Rule::enum(PlanType::class)],
            'subscriptionStartDate' => ['required', 'date'],
            'productsAllowed' => ['nullable', 'integer', 'min:0'],
            'salesAllowed' => ['nullable', 'integer', 'min:0'],
        ]);

        [$shop] = CreateShopWithOwner::handle(
            ['name' => $this->shopName, 'phone' => $this->shopPhone, 'email' => $this->shopEmail, 'address' => $this->shopAddress, 'business_category' => $this->businessCategory],
            ['name' => $this->userName, 'email' => $this->userEmail, 'password' => $this->password],
            [
                'plan_type' => $this->planType,
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_start_date' => $this->subscriptionStartDate,
                'products_allowed' => $this->productsAllowed !== '' ? $this->productsAllowed : null,
                'sales_allowed' => $this->salesAllowed !== '' ? $this->salesAllowed : null,
            ],
            emailVerified: true,
        );

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

                <x-ui.field label="Business category" name="businessCategory" for="businessCategory" class="sm:col-span-2" help="Sets which modules the shop sees at first and adds starter product categories. The owner can change module visibility later.">
                    <x-ui.select wire:model="businessCategory" id="businessCategory">
                        <option value="">Not set — every module visible</option>
                        @foreach (BusinessCategory::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Login Credentials" description="This becomes the shop's owner account, with full access. The owner can create additional staff logins and roles afterward.">
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
