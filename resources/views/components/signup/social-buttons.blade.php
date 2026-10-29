@props(['intent' => 'login', 'plan' => null])

@php($providers = \App\Enums\SocialProvider::configured())

@if ($providers !== [])
    <div {{ $attributes->class(['space-y-3']) }}>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-{{ count($providers) }}">
            @foreach ($providers as $provider)
                <a href="{{ route('oauth.redirect', array_filter(['provider' => $provider->value, 'intent' => $intent, 'plan' => $plan])) }}" class="inline-flex items-center justify-center gap-2.5 rounded-full border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-800 shadow-sm transition hover:border-slate-400 hover:bg-slate-50">
                    @if ($provider === \App\Enums\SocialProvider::Google)
                        <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    @else
                        <svg class="h-5 w-5" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/></svg>
                    @endif
                    Continue with {{ $provider->label() }}
                </a>
            @endforeach
        </div>
        <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-slate-400">
            <span class="h-px flex-1 bg-slate-200"></span>or<span class="h-px flex-1 bg-slate-200"></span>
        </div>
    </div>
@endif
