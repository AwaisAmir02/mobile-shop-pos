<?php

use App\Enums\SocialProvider;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public function disconnect(string $provider): void
    {
        $user = Auth::user();
        $social = SocialProvider::from($provider);
        $accounts = $user->socialAccounts()->get();

        if (! $user->hasPassword() && $accounts->count() <= 1) {
            $this->toastError('Set a password first — otherwise you won’t be able to log in.');

            return;
        }

        $user->socialAccounts()->where('provider', $social->value)->delete();
        $this->toastSuccess($social->label().' disconnected.');
    }

    public function with(): array
    {
        return [
            'linked' => Auth::user()->socialAccounts()->get()->keyBy(fn ($account) => $account->provider->value),
            'providers' => SocialProvider::configured(),
        ];
    }
}; ?>

<section>

    @if (session('oauth_status'))
        <p class="mb-4 rounded-lg bg-success-soft px-3 py-2 text-sm text-success" role="status">{{ session('oauth_status') }}</p>
    @endif
    @if (session('oauth_error'))
        <p class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ session('oauth_error') }}</p>
    @endif

    <ul class="divide-y divide-line rounded-xl border border-line">
        @foreach ($providers as $provider)
            <li class="flex items-center justify-between gap-4 px-4 py-3" wire:key="provider-{{ $provider->value }}">
                <div>
                    <p class="font-medium text-ink">{{ $provider->label() }}</p>
                    <p class="text-xs text-ink-faint">{{ $linked->has($provider->value) ? ($linked[$provider->value]->email ?? 'Connected') : 'Not connected' }}</p>
                </div>
                @if ($linked->has($provider->value))
                    <x-ui.button size="sm" variant="ghost" wire:click="disconnect('{{ $provider->value }}')" confirm="Disconnect {{ $provider->label() }}?">Disconnect</x-ui.button>
                @else
                    <a href="{{ route('oauth.redirect', ['provider' => $provider->value, 'intent' => 'link']) }}" class="text-sm font-semibold text-link hover:underline">Connect</a>
                @endif
            </li>
        @endforeach
    </ul>
</section>
