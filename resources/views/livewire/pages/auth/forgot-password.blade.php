<?php

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\VerificationCodes;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    #[Locked]
    public string $phoneStep = '';

    #[Locked]
    public string $sentVia = '';

    public string $code = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function sendPasswordResetLink(VerificationCodes $codes): void
    {
        $login = trim($this->email);

        if (! str_contains($login, '@') && PhoneNumber::isPakistaniMobile($login) && $codes->phoneChannelsAvailable()) {
            $this->sendPhoneCode($codes, PhoneNumber::normalize($login), false);

            return;
        }

        $this->validate([
            'email' => ['required', 'string', 'email'],
        ], ['email.email' => 'Enter your email address or a Pakistani mobile number.']);

        // Intentionally ignore the broker's status: whether the email matches an
        // account or not, the response to the user must be identical, otherwise
        // this form becomes a way to test which emails have accounts.
        Password::sendResetLink($this->only('email'));

        $this->reset('email');

        session()->flash('status', __('passwords.sent'));
    }

    protected function sendPhoneCode(VerificationCodes $codes, string $phone, bool $preferSms): void
    {
        $user = User::where('phone', $phone)->where('is_active', true)->first();

        try {
            $sentOn = $codes->send(OtpPurpose::PasswordReset, OtpChannel::WhatsApp, $phone, request()->ip(), $preferSms, deliver: $user !== null);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([$this->phoneStep ? 'code' : 'email' => $e->getMessage()]);
        }

        $this->phoneStep = $phone;
        $this->sentVia = $sentOn->value;
    }

    public function resendCode(VerificationCodes $codes, bool $sms = false): void
    {
        abort_unless($this->phoneStep !== '', 404);
        $this->sendPhoneCode($codes, $this->phoneStep, $sms);
        session()->flash('status', 'A new code is on its way.');
    }

    public function resetWithCode(VerificationCodes $codes): void
    {
        abort_unless($this->phoneStep !== '', 404);

        $key = 'phone-reset:'.$this->phoneStep.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Please try again later.']);
        }

        RateLimiter::hit($key, 900);

        $this->validate([
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], ['code.digits' => 'Enter the 6-digit code.']);

        $user = User::where('phone', $this->phoneStep)->where('is_active', true)->first();

        if (! $user || ! $codes->verify(OtpPurpose::PasswordReset, $this->phoneStep, $this->code)) {
            throw ValidationException::withMessages(['code' => 'That code is incorrect or has expired.']);
        }

        $user->forceFill(['password' => Hash::make($this->password), 'remember_token' => str()->random(60)])->save();

        RateLimiter::clear($key);
        session()->flash('status', __('passwords.reset'));

        $this->redirectRoute('login', navigate: true);
    }

    public function startOver(): void
    {
        $this->reset();
    }

    public function with(VerificationCodes $codes): array
    {
        return [
            'phoneAvailable' => $codes->phoneChannelsAvailable(),
            'smsAvailable' => $codes->smsAvailable(),
        ];
    }
}; ?>

<div>
    @if ($phoneStep)
        <div class="mb-4 text-sm text-slate-600">
            If an account uses {{ PhoneNumber::display($phoneStep) }}, we’ve sent it a 6-digit code by WhatsApp or SMS. Enter it below with your new password.
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form wire:submit="resetWithCode" class="space-y-5">
            <x-ui.field label="Verification code" name="code" for="code">
                <x-ui.input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="text-center text-xl font-bold tracking-[0.4em]" autofocus />
            </x-ui.field>

            <x-ui.field label="New password" name="password" for="password">
                <x-ui.input wire:model="password" id="password" type="password" autocomplete="new-password" />
            </x-ui.field>

            <x-ui.field label="Confirm new password" name="password_confirmation" for="password_confirmation">
                <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" />
            </x-ui.field>

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="resendCode" class="font-semibold text-brand-700 hover:underline">Resend code</button>
                    @if ($smsAvailable)
                        <button type="button" wire:click="resendCode(true)" class="font-semibold text-brand-700 hover:underline">Send by SMS</button>
                    @endif
                    <button type="button" wire:click="startOver" class="text-slate-500 hover:text-slate-800">Start over</button>
                </div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resetWithCode">Reset password</x-ui.button>
            </div>
        </form>
    @else
        <div class="mb-4 text-sm text-slate-600">
            {{ $phoneAvailable ? 'Forgot your password? Enter your email address and we’ll email you a reset link — or enter your mobile number and we’ll send you a code.' : __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form wire:submit="sendPasswordResetLink" class="space-y-5">
            <x-ui.field :label="$phoneAvailable ? 'Email or mobile number' : 'Email'" name="email">
                <x-ui.input wire:model="email" id="email" :type="$phoneAvailable ? 'text' : 'email'" name="email" required autofocus />
            </x-ui.field>

            <div class="flex items-center justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="sendPasswordResetLink">
                    {{ $phoneAvailable ? 'Send reset link or code' : __('Email Password Reset Link') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</div>
