@props(['guide'])

{{-- D14: the guide for the screen the user is on, opened by the header "?" button
     (or anything that dispatches open-guide, optionally with a section id).
     Every language is rendered and the toggle only shows one; the choice is
     remembered on this device. Plain Alpine, so it works from a modal too. --}}
@php
    $languages = \App\Support\Guide::LANGUAGES;
    $default = \App\Support\Guide::DEFAULT_LANGUAGE;
    $chrome = [
        'en' => ['close' => 'Close guide', 'stuck' => 'Still stuck?', 'reportNote' => 'Our team will see which screen you were on.'],
        'ur' => ['close' => 'Guide band karein', 'stuck' => 'Phir bhi samajh nahin aaya?', 'reportNote' => 'Hamari team ko pata chal jayega ke aap kis screen par thay.'],
    ];
@endphp

<div
    x-data="{
        open: false,
        lang: (() => { try { const saved = localStorage.getItem('hok.guide.lang'); return @js(array_keys($languages)).includes(saved) ? saved : @js($default); } catch (e) { return @js($default); } })(),
        setLang(language) {
            this.lang = language;
            try { localStorage.setItem('hok.guide.lang', language); } catch (e) {}
        },
        show(section) {
            this.returnTo = document.activeElement;
            this.open = true;
            this.$refs.report.href = this.report();
            this.$nextTick(() => {
                this.$refs.close.focus();
                const body = this.$refs.body;
                const target = section ? [...this.$root.querySelectorAll('[data-guide-section]')].find((el) => el.dataset.guideSection === section && el.offsetParent !== null) : null;
                // A short last section can't scroll to the top on its own, so leave room under it.
                body.style.paddingBottom = '';
                if (target) {
                    const below = body.scrollHeight - target.offsetTop + 12;
                    if (below < body.clientHeight) body.style.paddingBottom = (body.clientHeight - below + 20) + 'px';
                }
                body.scrollTop = target ? target.offsetTop - 12 : 0;
            });
        },
        hide() {
            this.open = false;
            this.returnTo?.focus?.();
        },
        report() {
            return @js(route('support.create')) + '?' + new URLSearchParams({ from: location.pathname + location.search, screen: document.title }).toString();
        },
        returnTo: null,
    }"
    x-on:open-guide.window="show($event.detail?.section ?? @js($guide['section'] ?? null))"
    data-guide="{{ $guide['key'] }}"
>
    <div x-show="open" x-cloak x-transition.opacity.duration.150ms x-on:click="hide()" class="fixed inset-0 z-dialog bg-scrim/40" aria-hidden="true"></div>

    <aside
        x-show="open"
        x-cloak
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        x-on:keydown.escape.stop="hide()"
        role="dialog"
        aria-modal="true"
        aria-labelledby="guide-title"
        data-guide-panel
        class="fixed inset-y-0 right-0 z-dialog flex w-full max-w-md flex-col border-l border-line bg-surface shadow-float"
        style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)"
    >
        <div class="border-b border-line px-5 pb-4 pt-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-on-primary-soft">Guide</p>
                    @foreach ($guide['languages'] as $code => $page)
                        <h2 x-show="lang === @js($code)" @if ($loop->first) id="guide-title" @endif class="mt-0.5 text-lg font-extrabold tracking-tight text-ink" lang="{{ $code === 'ur' ? 'ur-Latn' : $code }}">{{ $page['title'] }}</h2>
                    @endforeach
                </div>
                <button
                    type="button"
                    x-ref="close"
                    x-on:click="hide()"
                    class="-mr-1.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-faint hover:bg-surface-sunken hover:text-ink"
                    :aria-label="@js(collect($chrome)->map->close)[lang]"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="mt-3 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1" role="group" aria-label="Guide language">
                @foreach ($languages as $code => $name)
                    <button
                        type="button"
                        x-on:click="setLang(@js($code))"
                        :aria-pressed="(lang === @js($code)).toString()"
                        data-guide-lang="{{ $code }}"
                        class="rounded-full px-3 py-1 text-xs font-semibold transition-colors"
                        :class="lang === @js($code) ? 'bg-surface text-ink shadow-soft ring-1 ring-line' : 'text-ink-faint hover:text-ink'"
                    >{{ $name }}</button>
                @endforeach
            </div>
        </div>

        <div x-ref="body" class="relative min-h-0 flex-1 overflow-y-auto px-5 py-5">
            @foreach ($guide['languages'] as $code => $page)
                <div x-show="lang === @js($code)" lang="{{ $code === 'ur' ? 'ur-Latn' : $code }}" data-guide-language="{{ $code }}" class="space-y-6">
                    <p class="text-sm leading-relaxed text-ink-muted">{{ $page['summary'] }}</p>

                    @foreach ($page['sections'] as $section)
                        <section data-guide-section="{{ $section['id'] }}" class="space-y-2.5">
                            <h3 class="text-sm font-bold text-ink">{{ $section['heading'] }}</h3>

                            @if (filled($section['intro'] ?? null))
                                <p class="text-sm leading-relaxed text-ink-muted">{{ $section['intro'] }}</p>
                            @endif

                            @if (! empty($section['steps']))
                                <ol class="space-y-2">
                                    @foreach ($section['steps'] as $step)
                                        <li class="flex gap-2.5 text-sm leading-relaxed text-ink-muted">
                                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[11px] font-bold text-on-primary-soft">{{ $loop->iteration }}</span>
                                            <span>{{ $step }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif

                            @if (! empty($section['items']))
                                <dl class="divide-y divide-line rounded-xl border border-line">
                                    @foreach ($section['items'] as $item)
                                        <div class="px-3.5 py-2.5" data-guide-item>
                                            <dt class="text-sm font-semibold text-ink">{{ $item['label'] }}</dt>
                                            <dd class="mt-0.5 text-sm leading-relaxed text-ink-muted">{{ $item['text'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </section>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-line bg-surface-sunken/60 px-5 py-3.5">
            <div class="min-w-0 text-sm">
                @foreach ($chrome as $code => $text)
                    <div x-show="lang === @js($code)">
                        <p class="font-semibold text-ink">{{ $text['stuck'] }}</p>
                        <p class="text-xs text-ink-faint">{{ $text['reportNote'] }}</p>
                    </div>
                @endforeach
            </div>
            <a x-ref="report" href="{{ route('support.create') }}" wire:navigate x-on:focus="$el.href = report()" x-on:mouseenter="$el.href = report()" data-guide-report class="shrink-0 rounded-full border border-line bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink shadow-soft hover:border-line-strong">Report a problem</a>
        </div>
    </aside>
</div>
