<?php

use App\Actions\Support\OpenSupportTicket;
use App\Livewire\Concerns\ComposesSupportMessage;
use App\Support\SupportScreen;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Report a problem')] class extends Component
{
    use ComposesSupportMessage, WithFileUploads;

    /** Address of the screen the user was on, filled in by the sidebar link. */
    #[Url]
    public string $from = '';

    #[Url(as: 'screen')]
    public string $screenTitle = '';

    public bool $aboutScreen = true;

    public function submit(OpenSupportTicket $open): void
    {
        $this->validateComposer(bodyRequired: true);

        try {
            $ticket = $open->handle(
                Auth::user(),
                $this->body,
                $this->images,
                $this->aboutScreen ? $this->from : null,
                $this->aboutScreen ? $this->screenTitle : null,
                request()->userAgent(),
            );
        } catch (\RuntimeException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        session()->flash('support-opened', true);
        $this->redirectRoute('support.show', $ticket, navigate: true);
    }

    public function with(): array
    {
        return [
            'screen' => SupportScreen::resolve($this->from, $this->screenTitle),
        ];
    }
}; ?>

<div class="mx-auto max-w-2xl">
    <x-slot name="header">
        <x-ui.page-header back="{{ route('support.index', array_filter(['from' => $from, 'screen' => $screenTitle])) }}" back-label="Help & Support">Report a problem</x-ui.page-header>
    </x-slot>

    <x-ui.card>
        <div class="space-y-5">
            <div>
                <h2 class="text-base font-bold text-ink">What happened?</h2>
                <p class="mt-1 text-sm text-ink-faint">Tell us what you were trying to do and what went wrong. A photo or screenshot of the screen helps a lot.</p>
            </div>

            @if ($screen)
                <label class="flex items-start gap-3 rounded-xl border border-line bg-surface-sunken/60 p-3" data-captured-screen>
                    <input type="checkbox" wire:model="aboutScreen" class="mt-0.5 h-4 w-4 shrink-0 rounded border-line-strong bg-surface text-primary shadow-soft focus:ring-link focus:ring-offset-0">
                    <span class="text-sm">
                        <span class="font-semibold text-ink">About the screen I was on:</span>
                        <span class="text-ink-muted">{{ $screen['title'] ?? Str::of($screen['route'])->replace(['.', '-'], ' ')->title() }}</span>
                        <span class="block text-xs text-ink-faint">We'll see which screen this was. Untick if your question is about something else.</span>
                    </span>
                </label>
            @endif

            <x-support.composer
                submit="submit"
                :images="$images"
                label="Describe the problem"
                placeholder="e.g. When I press Complete Sale on the sales screen, nothing happens."
                button="Send to Support"
                rows="6"
            />
        </div>
    </x-ui.card>
</div>
