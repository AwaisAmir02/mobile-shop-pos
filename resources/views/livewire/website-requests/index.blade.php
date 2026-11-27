<?php

use App\Actions\WebsiteRequests;
use App\Livewire\Concerns\ComposesSupportMessage;
use App\Livewire\Concerns\Toasts;
use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * "Build my website" (O9, App\Actions\WebsiteRequests): what the shop wants,
 * sent to our team as a support conversation, and where the request stands.
 * Routed behind shop.tool:website.
 */
new #[Layout('layouts.app')] #[Title('Get a Website')] class extends Component
{
    use ComposesSupportMessage, Toasts, WithFileUploads;

    public string $about = '';
    public string $goal = 'orders';
    public string $products = '';
    public string $domain = '';
    public string $references = '';
    public string $deadline = '';
    public string $phone = '';

    public function mount(): void
    {
        $this->phone = (string) (Auth::user()->shop?->phone ?? '');
    }

    public function submit(): void
    {
        $data = $this->validate(WebsiteRequests::rules() + ['images' => ['array', 'max:'.config('support.max_images')], 'images.*' => $this->imageRules()], [], ['about' => 'about your business', 'products' => 'number of products']);

        $request = WebsiteRequests::submit(Auth::user(), collect($data)->except('images')->all(), $this->images, request()->userAgent());

        $this->reset(['about', 'products', 'domain', 'references', 'deadline', 'images']);
        $this->toastSuccess('Request '.$request->number().' sent. We\'ll reply in its conversation.');
    }

    public function with(): array
    {
        return [
            'requests' => WebsiteRequest::query()->with('ticket')->latest('id')->get(),
            'goals' => WebsiteRequests::GOALS,
        ];
    }
}; ?>

<div class="mx-auto max-w-3xl">
    <x-slot name="header">
        <x-ui.page-header subtitle="No website yet? Tell us what you need and our team will build it for you">Get a Website</x-ui.page-header>
    </x-slot>

    @foreach ($requests as $request)
        <x-ui.card class="mb-6" :title="$request->number().' · '.$request->created_at->format('d M Y')" wire:key="request-{{ $request->id }}" data-website-request="{{ $request->id }}">
            <div class="space-y-2 text-sm">
                <x-ui.badge :variant="$request->status->badge()">{{ $request->status->label() }}</x-ui.badge>
                @if ($request->site_url)
                    <p>Your site: <a href="{{ $request->site_url }}" target="_blank" rel="noopener" class="font-semibold text-link hover:underline">{{ $request->site_url }}</a></p>
                @endif
                @if ($request->ticket)
                    <p><a href="{{ route('support.show', $request->ticket) }}" wire:navigate class="font-semibold text-link hover:underline">Open the conversation</a> to add details, photos or your logo.</p>
                @endif
            </div>
        </x-ui.card>
    @endforeach

    @if ($requests->contains(fn ($request) => $request->status->isOpen()))
        <p class="text-sm text-ink-faint">You have a request open. Add to it in its conversation.</p>
    @else
        <x-ui.card title="What should your website do?" description="We build it by hand and agree the price with you first. Once it's ready it's connected to your stock and orders here.">
            <form wire:submit="submit" class="space-y-4" data-website-request-form>
                <x-ui.field label="About your business" name="about" for="about" help="What you sell, and who buys it">
                    <textarea wire:model="about" id="about" rows="4" maxlength="2000" class="block w-full rounded-lg border-line-strong bg-surface px-3 py-2 text-base text-ink shadow-soft focus:border-link focus:ring-link sm:text-sm"></textarea>
                </x-ui.field>
                <x-ui.field label="The site should" name="goal" for="goal">
                    <x-ui.select wire:model="goal" id="goal">
                        @foreach ($goals as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="About how many products" name="products" for="products">
                        <x-ui.input wire:model="products" id="products" type="number" min="1" />
                    </x-ui.field>
                    <x-ui.field label="Your domain, if you have one" name="domain" for="domain" help="Blank: you need one">
                        <x-ui.input wire:model="domain" id="domain" placeholder="mybrand.pk" />
                    </x-ui.field>
                    <x-ui.field label="Wanted by" name="deadline" for="deadline" help="Optional">
                        <x-ui.input wire:model="deadline" id="deadline" type="date" />
                    </x-ui.field>
                    <x-ui.field label="Phone for our team" name="phone" for="phone">
                        <x-ui.input wire:model="phone" id="phone" inputmode="tel" />
                    </x-ui.field>
                </div>
                <x-ui.field label="Sites you like" name="references" for="references" help="Optional: addresses of sites whose look you like">
                    <x-ui.input wire:model="references" id="references" maxlength="1000" />
                </x-ui.field>
                <x-ui.field label="Your logo or photos" name="images" for="newImages" help="Optional">
                    <input type="file" wire:model="newImages" id="newImages" multiple accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-ink-muted">
                </x-ui.field>
                @if ($images !== [])
                    <p class="text-xs text-ink-faint">{{ count($images) }} {{ \Illuminate\Support\Str::plural('photo', count($images)) }} added</p>
                @endif
                <x-ui.button type="submit" wire:loading.attr="disabled">Send request</x-ui.button>
            </form>
        </x-ui.card>
    @endif
</div>
