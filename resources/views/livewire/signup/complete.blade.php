<?php

use App\Enums\BusinessCategory;
use App\Enums\SocialProvider;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Billing\SubscriptionPaymentService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $planId = '';
    public string $shopName = '';
    public string $businessCategory = '';
    public string $ownerName = '';
    public string $phone = '';

    public function mount(?string $plan = null): void
    {
        $pending = session(SocialLoginController::PENDING_KEY);

        if (! $pending) {
            $this->redirectRoute('site.signup');

            return;
        }

        $this->ownerName = $pending['name'];

        $plans = Plan::active()->ordered()->get();
        $chosen = $plans->firstWhere('id', (int) $plan) ?? $plans->firstWhere('is_featured', true) ?? $plans->first();
        $this->planId = $chosen ? (string) $chosen->id : '';
    }

    protected function ip(): string
    {
        return (string) request()->ip();
    }

    public function submit(SubscriptionPaymentService $payments): void
    {
        $pending = session(SocialLoginController::PENDING_KEY);

        if (! $pending) {
            $this->redirectRoute('site.signup');

            return;
        }

        $this->shopName = trim($this->shopName);
        $this->ownerName = trim($this->ownerName);

        $this->validate([
            'planId' => ['required', 'integer', fn ($attribute, $value, $fail) => Plan::active()->whereKey($value)->exists() ? null : $fail('Please choose an available plan.')],
            'shopName' => ['required', 'string', 'min:2', 'max:120'],
            'businessCategory' => ['required', Rule::enum(BusinessCategory::class)],
            'ownerName' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', fn ($attribute, $value, $fail) => PhoneNumber::isPakistaniMobile($value) ? null : $fail('Enter a Pakistani mobile number, e.g. 0300 1234567.')],
        ], [], ['shopName' => 'shop name', 'businessCategory' => 'kind of business', 'ownerName' => 'your name', 'phone' => 'mobile number']);

        $limits = config('billing.signup');

        if (RateLimiter::tooManyAttempts('signup-hour:'.$this->ip(), $limits['per_hour']) || RateLimiter::tooManyAttempts('signup-day:'.$this->ip(), $limits['per_day'])) {
            throw ValidationException::withMessages(['form' => 'Too many shops have been created from this connection. Please try again later or contact us.']);
        }

        if (User::where('email', $pending['email'])->exists() || UserSocialAccount::where('provider', $pending['provider'])->where('provider_user_id', $pending['provider_user_id'])->exists()) {
            session()->forget(SocialLoginController::PENDING_KEY);
            throw ValidationException::withMessages(['form' => 'An account already exists for '.$pending['email'].'. Please log in instead.']);
        }

        $user = DB::transaction(function () use ($payments, $pending) {
            [, $user] = $payments->register(
                Plan::active()->findOrFail((int) $this->planId),
                ['name' => $this->shopName, 'phone' => '+'.PhoneNumber::normalize($this->phone), 'email' => $pending['email'], 'business_category' => $this->businessCategory],
                ['name' => $this->ownerName, 'email' => $pending['email']],
                emailVerified: true,
            );

            UserSocialAccount::create([
                'user_id' => $user->id,
                'provider' => $pending['provider'],
                'provider_user_id' => $pending['provider_user_id'],
                'email' => $pending['email'],
            ]);

            return $user;
        });

        RateLimiter::hit('signup-hour:'.$this->ip(), 3600);
        RateLimiter::hit('signup-day:'.$this->ip(), 86400);
        session()->forget(SocialLoginController::PENDING_KEY);

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('billing.index');
    }

    public function with(): array
    {
        $pending = session(SocialLoginController::PENDING_KEY);

        return [
            'plans' => Plan::active()->ordered()->get(),
            'email' => $pending['email'] ?? '',
            'providerLabel' => isset($pending['provider']) ? SocialProvider::from($pending['provider'])->label() : '',
        ];
    }
}; ?>

<div class="grid grid-cols-1 gap-8 lg:grid-cols-5">
    <form wire:submit="submit" class="space-y-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-brand-950/5 sm:p-8 lg:col-span-3" novalidate>
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">Signed in with {{ $providerLabel }} as <span class="font-semibold">{{ $email }}</span>.</p>

        <x-signup.plan-picker :plans="$plans" :selected="$planId" legend="1. Choose your plan" />

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-slate-900">2. Your shop</legend>
            <x-ui.field label="Shop name" name="shopName" for="shopName">
                <x-ui.input wire:model="shopName" id="shopName" autocomplete="organization" maxlength="120" required />
            </x-ui.field>
            <div>
                    <label for="businessCategory" class="block text-sm font-medium text-slate-700">What kind of business?</label>
                    <select wire:model.live="businessCategory" id="businessCategory" class="mt-1.5 block w-full rounded-lg border-slate-300 py-2.5 pl-3.5 pr-10 text-base text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Choose one…</option>
                        @foreach (BusinessCategory::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">We’ll set up the screens your kind of shop needs. You can change this later.</p>
                    <x-ui.error :messages="$errors->get('businessCategory')" class="mt-1" />
                </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Your name" name="ownerName" for="ownerName">
                    <x-ui.input wire:model="ownerName" id="ownerName" autocomplete="name" maxlength="120" required />
                </x-ui.field>
                <x-ui.field label="Mobile number" name="phone" for="phone" help="Shop contact number, e.g. 0300 1234567">
                    <x-ui.input wire:model="phone" id="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" required />
                </x-ui.field>
            </div>
        </fieldset>

        <x-ui.error :messages="$errors->get('form')" />

        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-accent-500 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-accent-500/30 transition hover:bg-accent-600 disabled:opacity-70">
            <span wire:loading.remove wire:target="submit">Create my shop</span>
            <span wire:loading wire:target="submit">Creating your shop…</span>
        </button>
    </form>

    <x-signup.plan-summary :plan="$plans->firstWhere('id', (int) $planId)" />
</div>
