<?php

use App\Actions\CreateShopWithOwner;
use App\Enums\BusinessCategory;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\VerificationCodes;
use App\Services\Billing\SubscriptionPaymentService;
use App\Support\PhoneNumber;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    public const SESSION_KEY = 'signup.pending';

    #[Locked]
    public int $renderedAt = 0;

    #[Locked]
    public string $step = 'details';

    #[Locked]
    public string $sentVia = '';

    #[Locked]
    public bool $resent = false;

    public string $planId = '';
    public string $method = 'email';
    public string $shopName = '';
    public string $businessCategory = '';
    public string $ownerName = '';
    public string $phone = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $website = '';
    public string $turnstileToken = '';
    public string $code = '';

    public function mount(?string $plan = null): void
    {
        $this->renderedAt = now()->getTimestamp();
        session()->forget(self::SESSION_KEY);

        $plans = Plan::active()->ordered()->get();
        $chosen = $plans->firstWhere('id', (int) $plan) ?? $plans->firstWhere('is_featured', true) ?? $plans->first();
        $this->planId = $chosen ? (string) $chosen->id : '';
    }

    protected function ip(): string
    {
        return (string) request()->ip();
    }

    protected function stopSignup(string $message, string $field = 'form'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    protected function ensureCapacity(): void
    {
        $limits = config('billing.signup');

        if (RateLimiter::tooManyAttempts('signup-hour:'.$this->ip(), $limits['per_hour']) || RateLimiter::tooManyAttempts('signup-day:'.$this->ip(), $limits['per_day'])) {
            $this->stopSignup('Too many shops have been created from this connection. Please try again later or contact us.');
        }
    }

    protected function countAttempt(): void
    {
        $key = 'signup-attempt:'.$this->ip();

        if (RateLimiter::tooManyAttempts($key, config('billing.signup.attempts_per_10_minutes'))) {
            $this->stopSignup('Too many attempts. Please wait a few minutes and try again.', $this->step === 'verify' ? 'code' : 'form');
        }

        RateLimiter::hit($key, 600);
    }

    public function submit(VerificationCodes $codes): void
    {
        $this->countAttempt();

        if ($this->website !== '' || now()->getTimestamp() - $this->renderedAt < config('billing.signup.min_seconds')) {
            $this->stopSignup('We couldn’t create your shop. Please check your details and try again.');
        }

        if (! Turnstile::verify($this->turnstileToken, $this->ip())) {
            $this->turnstileToken = '';
            $this->dispatch('turnstile-reset');
            $this->stopSignup('Please complete the security check.');
        }

        if (! $codes->phoneChannelsAvailable()) {
            $this->method = 'email';
        }

        $this->shopName = trim($this->shopName);
        $this->ownerName = trim($this->ownerName);
        $this->email = mb_strtolower(trim($this->email));

        $byPhone = $this->method === 'phone';

        $this->validate([
            'planId' => ['required', 'integer', fn ($attribute, $value, $fail) => Plan::active()->whereKey($value)->exists() ? null : $fail('Please choose an available plan.')],
            'method' => ['required', 'in:email,phone'],
            'shopName' => ['required', 'string', 'min:2', 'max:120'],
            'businessCategory' => ['required', Rule::enum(BusinessCategory::class)],
            'ownerName' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) use ($byPhone) {
                if (! PhoneNumber::isPakistaniMobile($value)) {
                    $fail('Enter a Pakistani mobile number, e.g. 0300 1234567.');
                } elseif ($byPhone && User::where('phone', PhoneNumber::normalize($value))->exists()) {
                    $fail('An account already uses this mobile number. Log in instead.');
                }
            }],
            'email' => [$byPhone ? 'nullable' : 'required', 'email:rfc,strict', 'max:255', 'unique:users,email'],
            'password' => CreateShopWithOwner::ownerRules()['password'],
        ], [], [
            'shopName' => 'shop name',
            'businessCategory' => 'kind of business',
            'ownerName' => 'your name',
            'phone' => 'mobile number',
        ]);

        $this->ensureCapacity();

        $phone = PhoneNumber::normalize($this->phone);
        $destination = $byPhone ? $phone : $this->email;

        session([self::SESSION_KEY => [
            'plan_id' => (int) $this->planId,
            'method' => $this->method,
            'shop_name' => $this->shopName,
            'business_category' => $this->businessCategory,
            'owner_name' => $this->ownerName,
            'phone' => $phone,
            'email' => $this->email !== '' ? $this->email : null,
            'password_hash' => Hash::make($this->password),
            'destination' => $destination,
        ]]);

        $this->reset(['password', 'password_confirmation']);
        $this->sendCode($codes, false);
    }

    protected function sendCode(VerificationCodes $codes, bool $preferSms): void
    {
        $pending = session(self::SESSION_KEY) ?? $this->stopSignup('Your session expired. Please fill in the form again.');
        $channel = $pending['method'] === 'phone' ? OtpChannel::WhatsApp : OtpChannel::Email;

        try {
            $sentOn = $codes->send(OtpPurpose::Signup, $channel, $pending['destination'], $this->ip(), $preferSms);
        } catch (\RuntimeException $e) {
            $this->stopSignup($e->getMessage(), $this->step === 'verify' ? 'code' : 'form');
        }

        $this->sentVia = $sentOn->value;
        $this->step = 'verify';
        $this->code = '';
    }

    public function resend(VerificationCodes $codes, bool $sms = false): void
    {
        $this->resetErrorBag();
        $this->sendCode($codes, $sms);
        $this->resent = true;
    }

    public function editDetails(): void
    {
        $this->step = 'details';
        $this->code = '';
        $this->resetErrorBag();
        session()->forget(self::SESSION_KEY);
    }

    public function verify(VerificationCodes $codes, SubscriptionPaymentService $payments): void
    {
        $pending = session(self::SESSION_KEY);

        if (! $pending) {
            $this->editDetails();
            $this->stopSignup('Your session expired. Please fill in the form again.');
        }

        $this->countAttempt();
        $this->validate(['code' => ['required', 'digits:6']], ['code.digits' => 'Enter the 6-digit code.']);

        if (! $codes->verify(OtpPurpose::Signup, $pending['destination'], $this->code)) {
            $this->stopSignup('That code is incorrect or has expired.', 'code');
        }

        $byPhone = $pending['method'] === 'phone';

        if (($pending['email'] && User::where('email', $pending['email'])->exists()) || ($byPhone && User::where('phone', $pending['phone'])->exists())) {
            $this->editDetails();
            $this->stopSignup('An account with these details was just created. Please log in instead.');
        }

        $this->ensureCapacity();

        [, $user] = $payments->register(
            Plan::active()->findOrFail($pending['plan_id']),
            ['name' => $pending['shop_name'], 'phone' => '+'.$pending['phone'], 'email' => $pending['email'], 'business_category' => $pending['business_category'] ?? null],
            [
                'name' => $pending['owner_name'],
                'email' => $pending['email'],
                'phone' => $byPhone ? $pending['phone'] : null,
                'phone_verified' => $byPhone,
                'password_hash' => $pending['password_hash'],
            ],
            emailVerified: ! $byPhone,
        );

        RateLimiter::hit('signup-hour:'.$this->ip(), 3600);
        RateLimiter::hit('signup-day:'.$this->ip(), 86400);
        RateLimiter::clear('signup-attempt:'.$this->ip());
        session()->forget(self::SESSION_KEY);

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('billing.index');
    }

    public function with(VerificationCodes $codes): array
    {
        $pending = session(self::SESSION_KEY);

        return [
            'plans' => Plan::active()->ordered()->get(),
            'turnstile' => Turnstile::enabled() ? Turnstile::siteKey() : null,
            'phoneAvailable' => $codes->phoneChannelsAvailable(),
            'smsAvailable' => $codes->smsAvailable(),
            'destinationLabel' => $pending ? ($pending['method'] === 'phone' ? PhoneNumber::display($pending['destination']) : $pending['destination']) : '',
        ];
    }
}; ?>

<div class="grid grid-cols-1 gap-8 lg:grid-cols-5">
    @if ($step === 'verify')
        <form wire:submit="verify" class="space-y-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-brand-950/5 sm:p-8 lg:col-span-3" novalidate>
            <div>
                <h2 class="text-xl font-bold text-slate-900">Enter your verification code</h2>
                <p class="mt-2 text-sm text-slate-600">
                    We sent a 6-digit code by <span class="font-semibold">{{ OtpChannel::from($sentVia)->label() }}</span> to <span class="font-semibold">{{ $destinationLabel }}</span>. It expires in {{ config('verification.code_ttl_minutes') }} minutes.
                </p>
            </div>

            @if ($resent)
                <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">A new code is on its way.</p>
            @endif

            <x-ui.field label="Verification code" name="code" for="code">
                <x-ui.input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" class="text-center text-2xl font-bold tracking-[0.5em]" autofocus />
            </x-ui.field>

            <button type="submit" wire:loading.attr="disabled" wire:target="verify" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-accent-500 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-accent-500/30 transition hover:bg-accent-600 disabled:opacity-70">
                <span wire:loading.remove wire:target="verify">Verify &amp; create my shop</span>
                <span wire:loading wire:target="verify">Creating your shop…</span>
            </button>

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <button type="button" wire:click="editDetails" class="font-medium text-slate-600 hover:text-slate-900">← Change details</button>
                <div class="flex flex-wrap gap-4">
                    <button type="button" wire:click="resend" wire:loading.attr="disabled" class="font-semibold text-brand-700 hover:underline">Resend code</button>
                    @if ($sentVia === 'whatsapp' && $smsAvailable)
                        <button type="button" wire:click="resend(true)" wire:loading.attr="disabled" class="font-semibold text-brand-700 hover:underline">Send by SMS instead</button>
                    @endif
                </div>
            </div>
        </form>
    @else
        <form wire:submit="submit" class="space-y-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-brand-950/5 sm:p-8 lg:col-span-3" novalidate>
            @if ($plans->isEmpty())
                <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Plans are being updated. <a href="{{ route('site.contact') }}" class="font-semibold underline">Contact us</a> and we’ll set your shop up.</p>
            @else
                <x-signup.plan-picker :plans="$plans" :selected="$planId" legend="1. Choose your plan" />
            @endif

            <x-signup.social-buttons intent="signup" :plan="$planId" />

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
                    <x-ui.field label="Mobile number" name="phone" for="phone" help="Pakistani mobile, e.g. 0300 1234567">
                        <x-ui.input wire:model="phone" id="phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" required />
                    </x-ui.field>
                </div>
            </fieldset>

            <fieldset class="space-y-4">
                <legend class="text-sm font-semibold text-slate-900">3. Your login</legend>

                @if ($phoneAvailable)
                    <div class="grid grid-cols-2 gap-2 rounded-full bg-slate-100 p-1 text-sm font-semibold" role="radiogroup" aria-label="Log in with">
                        @foreach (['email' => 'Email', 'phone' => 'Mobile number'] as $value => $label)
                            <label @class(['cursor-pointer rounded-full px-4 py-2 text-center transition', 'bg-white text-brand-700 shadow-sm' => $method === $value, 'text-slate-600' => $method !== $value])>
                                <input type="radio" wire:model.live="method" value="{{ $value }}" class="sr-only"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500">{{ $method === 'phone' ? 'We’ll send a code to your mobile number on WhatsApp (or SMS) to confirm it.' : 'We’ll email you a code to confirm your address.' }}</p>
                @endif

                @if ($method === 'email' || ! $phoneAvailable)
                    <x-ui.field label="Email" name="email" for="email">
                        <x-ui.input wire:model="email" id="email" type="email" autocomplete="email" maxlength="255" required />
                    </x-ui.field>
                @else
                    <x-ui.field label="Email" name="email" for="email" help="Optional">
                        <x-ui.input wire:model="email" id="email" type="email" autocomplete="email" maxlength="255" />
                    </x-ui.field>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Password" name="password" for="password" help="At least 8 characters">
                        <x-ui.input wire:model="password" id="password" type="password" autocomplete="new-password" required />
                    </x-ui.field>
                    <x-ui.field label="Confirm password" name="password_confirmation" for="password_confirmation">
                        <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" required />
                    </x-ui.field>
                </div>
            </fieldset>

            <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            @if ($turnstile)
                <div wire:ignore x-data x-init="
                    const render = () => window.turnstile.render($refs.box, { sitekey: @js($turnstile), callback: (token) => $wire.set('turnstileToken', token, false) });
                    window.turnstile ? render() : (window.onTurnstileReady = render);
                    $wire.on('turnstile-reset', () => window.turnstile && window.turnstile.reset($refs.box));
                ">
                    <div x-ref="box"></div>
                </div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileReady" async defer></script>
            @endif

            <x-ui.error :messages="$errors->get('form')" />

            <div class="space-y-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-accent-500 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-accent-500/30 transition hover:bg-accent-600 disabled:opacity-70" @disabled($plans->isEmpty())>
                    <span wire:loading.remove wire:target="submit">Continue</span>
                    <span wire:loading wire:target="submit">Sending your code…</span>
                </button>
                <p class="text-center text-sm text-slate-500">Already have an account? <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:underline">Log in</a></p>
            </div>
        </form>
    @endif

    <x-signup.plan-summary :plan="$plans->firstWhere('id', (int) $planId)" />
</div>
