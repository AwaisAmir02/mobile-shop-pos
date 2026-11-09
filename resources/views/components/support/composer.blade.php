@props([
    'submit',
    'images' => [],
    'label' => 'Message',
    'placeholder' => '',
    'button' => 'Send',
    'rows' => 4,
])

{{-- Message box with photos, used by ComposesSupportMessage components. --}}
<form wire:submit="{{ $submit }}" {{ $attributes->class(['space-y-3']) }}>
    <div>
        <label for="support-body" class="sr-only">{{ $label }}</label>
        <textarea
            wire:model="body"
            id="support-body"
            rows="{{ $rows }}"
            maxlength="{{ config('support.message_max_chars') }}"
            placeholder="{{ $placeholder }}"
            class="block w-full rounded-lg border-line-strong bg-surface px-3 py-2 text-base text-ink shadow-soft placeholder:text-ink-faint focus:border-link focus:ring-link sm:text-sm"
        ></textarea>
        <x-ui.error :messages="$errors->get('body')" class="mt-1" />
    </div>

    @if (count($images))
        <ul class="flex flex-wrap gap-2" aria-label="Photos to send">
            @foreach ($images as $index => $image)
                <li wire:key="support-image-{{ $index }}-{{ $image->getFilename() }}" class="relative">
                    @if ($image->isPreviewable())
                        <img src="{{ $image->temporaryUrl() }}" alt="{{ $image->getClientOriginalName() }}" class="h-20 w-20 rounded-xl border border-line object-cover">
                    @else
                        <span class="flex h-20 w-20 items-center justify-center rounded-xl border border-line bg-surface-sunken p-1 text-center text-xs text-ink-faint">{{ Str::limit($image->getClientOriginalName(), 18) }}</span>
                    @endif
                    <button
                        type="button"
                        wire:click="removeImage({{ $index }})"
                        class="absolute -right-1.5 -top-1.5 flex h-6 w-6 items-center justify-center rounded-full border border-line bg-surface-raised text-ink-muted shadow-soft hover:text-danger"
                        aria-label="Remove {{ $image->getClientOriginalName() }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @php
        $photoErrors = collect($errors->get('images'))
            ->merge(collect($errors->get('images.*'))->flatten())
            ->merge(collect($errors->get('newImages.*'))->flatten())
            ->unique()->values()->all();
    @endphp
    <x-ui.error :messages="$photoErrors" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        @if (count($images) < config('support.max_images'))
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-line-strong bg-surface px-3.5 py-2 text-sm font-semibold text-ink-muted shadow-soft transition-colors hover:bg-surface-sunken hover:text-ink focus-within:ring-2 focus-within:ring-link">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                </svg>
                <span wire:loading.remove wire:target="newImages">Add photos</span>
                <span wire:loading wire:target="newImages">Uploading…</span>
                <input type="file" wire:model="newImages" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" data-support-photos>
            </label>
        @else
            <p class="text-xs text-ink-faint">{{ config('support.max_images') }} photos is the most per message.</p>
        @endif

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="{{ $submit }},newImages">{{ $button }}</x-ui.button>
    </div>
    <p class="text-xs text-ink-faint">Up to {{ config('support.max_images') }} photos, JPG, PNG or WebP, {{ (int) (config('support.image_max_kb') / 1024) }} MB each.</p>
</form>
