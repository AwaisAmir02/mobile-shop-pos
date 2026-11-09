<?php

use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use Toasts, WithFileUploads;

    /** Largest photo accepted, in kilobytes. */
    public const MAX_KB = 2048;

    public $photo = null;

    public function updatedPhoto(): void
    {
        $this->validateOnly('photo', $this->rules());
    }

    protected function rules(): array
    {
        return ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB]];
    }

    protected function messages(): array
    {
        return [
            'photo.image' => 'Choose a photo: JPG, PNG or WebP.',
            'photo.mimes' => 'Choose a photo: JPG, PNG or WebP.',
            'photo.max' => 'That photo is too large. The limit is 2 MB.',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $user = Auth::user();
        $previous = $user->avatar_path;

        $user->update(['avatar_path' => $this->photo->store('avatars', 'public')]);

        // The old file goes only once the new one is saved.
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        $this->reset('photo');
        $this->dispatch('avatar-updated');
        $this->toastSuccess('Profile photo updated.');
    }

    public function cancel(): void
    {
        $this->reset('photo');
        $this->resetErrorBag();
    }

    public function remove(): void
    {
        $user = Auth::user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        $this->reset('photo');
        $this->dispatch('avatar-updated');
        $this->toastSuccess('Profile photo removed.');
    }

    public function with(): array
    {
        $preview = null;

        if ($this->photo && ! $this->getErrorBag()->has('photo')) {
            try {
                $preview = $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null;
            } catch (\Throwable) {
                $preview = null;
            }
        }

        return ['user' => Auth::user(), 'preview' => $preview];
    }
}; ?>

<div class="flex flex-col gap-5 sm:flex-row sm:items-center">
    <x-ui.avatar :user="$user" :src="$preview" size="h-20 w-20 text-3xl" />

    <div class="min-w-0 flex-1">
        @if ($photo && $preview)
            <p class="text-sm font-medium text-ink">Looks good? Save it as your photo.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button type="button" size="sm" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save photo</x-ui.button>
                <x-ui.button type="button" size="sm" variant="secondary" wire:click="cancel">Cancel</x-ui.button>
            </div>
        @else
            <p class="text-sm text-ink-muted">
                {{ $user->avatar_path ? 'Shown next to your name at the top of every screen.' : 'Add a photo to show next to your name instead of your initial.' }}
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <label for="avatar-photo" class="ui-btn inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-full border border-line bg-surface px-3 py-[5px] text-sm font-semibold text-ink shadow-soft transition-colors hover:border-line-strong hover:bg-surface-sunken focus-within:ring-2 focus-within:ring-link">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    {{ $user->avatar_path ? 'Replace photo' : 'Upload photo' }}
                    <input type="file" id="avatar-photo" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="sr-only">
                </label>

                @if ($user->avatar_path)
                    <x-ui.button
                        type="button"
                        size="sm"
                        variant="danger-ghost"
                        wire:click="remove"
                        confirm="Remove your profile photo? Your initial will show instead."
                        confirm-label="Remove"
                    >Remove</x-ui.button>
                @endif

                <span wire:loading wire:target="photo" class="text-sm text-ink-faint">Uploading…</span>
            </div>
            <p class="mt-2 text-xs text-ink-faint">JPG, PNG or WebP, up to 2 MB.</p>
        @endif

        <x-ui.error :messages="$errors->get('photo')" class="mt-2" />
    </div>
</div>
