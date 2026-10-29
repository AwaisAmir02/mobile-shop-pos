<?php

use App\Enums\PostKind;
use App\Enums\PostStatus;
use App\Enums\PostSurface;
use App\Enums\WhatsAppOrderStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppOrder;
use App\Services\Marketing\PostComposer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Post')] class extends Component
{
    use Toasts;

    public MarketingPost $post;

    public string $caption = '';
    public array $surfaces = [];
    public string $scheduleAt = '';

    public function mount(MarketingPost $post): void
    {
        $shop = Auth::user()->shop;
        abort_unless($shop?->whatsapp_enabled || $shop?->social_enabled, 403);
        abort_if($post->kind === PostKind::QrCode, 404);

        $this->post = $post;
        $this->caption = (string) $post->caption;
        $this->surfaces = $post->targets()->pluck('surface')->map(fn ($s) => $s instanceof PostSurface ? $s->value : $s)->all();
        $this->scheduleAt = $post->scheduled_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function saveCaption(): void
    {
        $this->validate(['caption' => ['required', 'string', 'max:2200']]);
        $this->post->update(['caption' => $this->caption]);
        $this->toastSuccess('Caption saved.');
    }

    public function publish(PostComposer $composer): void
    {
        abort_unless(Auth::user()->shop?->social_enabled, 403);

        $this->validate([
            'caption' => ['required', 'string', 'max:2200'],
            'surfaces' => ['required', 'array', 'min:1'],
            'surfaces.*' => ['string', 'in:'.implode(',', array_column(PostSurface::cases(), 'value'))],
            'scheduleAt' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(60)->toDateString()],
        ], ['surfaces.required' => 'Pick at least one place to post.']);

        try {
            $composer->schedule($this->post, $this->surfaces, $this->scheduleAt !== '' ? Carbon::parse($this->scheduleAt) : null, $this->caption);
        } catch (\RuntimeException $e) {
            $this->addError('surfaces', $e->getMessage());

            return;
        }

        $this->post->refresh();
        $this->toastSuccess($this->post->status === PostStatus::Scheduled ? 'Post scheduled for '.$this->post->scheduled_at->format('d M, h:i A').'.' : 'Publishing started.');
    }

    public function retryFailed(PostComposer $composer): void
    {
        abort_unless(Auth::user()->shop?->social_enabled, 403);

        $this->post->targets()->where('status', 'failed')->update(['status' => 'pending', 'error' => null]);
        $this->post->update(['status' => PostStatus::Publishing]);
        $composer->dispatchTargets($this->post);
        $this->post->refresh();
        $this->toastSuccess('Retrying failed placements.');
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $connected = collect(PostSurface::cases())->mapWithKeys(fn (PostSurface $s) => [
            $s->value => ShopChannelConnection::activeFor($shop->id, $s->provider()) !== null,
        ]);

        $orders = WhatsAppOrder::query()->where('origin_ref', $this->post->ref_code)->get(['status', 'sale_id']);

        return [
            'shop' => $shop,
            'connected' => $connected,
            'targets' => $this->post->targets()->get(),
            'editable' => in_array($this->post->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed], true),
            'ordersPlaced' => $orders->where('status', '!=', WhatsAppOrderStatus::Collecting)->count(),
            'ordersAccepted' => $orders->where('status', WhatsAppOrderStatus::Accepted)->count(),
            'shareLink' => PostComposer::whatsappLink($shop, 'Hi! '.$this->post->refTag()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.posts" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-semibold text-slate-900">{{ $post->title }}</h2>
        <x-ui.badge :variant="$post->status->badge()">{{ $post->status->label() }}</x-ui.badge>
        <x-ui.badge variant="brand">{{ $post->refTag() }}</x-ui.badge>
        @if ($shop->ads_enabled && auth()->user()->hasAccessTo('marketing-ads'))
            <a href="{{ route('marketing.ads.create', ['post' => $post->id]) }}" wire:navigate><x-ui.button size="sm" variant="secondary">Boost with an ad</x-ui.button></a>
        @endif
        <span class="text-sm text-slate-500">{{ $ordersPlaced }} WhatsApp {{ \Illuminate\Support\Str::plural('order', $ordersPlaced) }} placed · {{ $ordersAccepted }} accepted</span>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card title="Images" :padding="false">
                <div class="grid grid-cols-2 gap-3 p-4">
                    <figure>
                        <img src="{{ $post->imageUrl() }}" alt="Feed image for {{ $post->title }}" class="aspect-square w-full rounded-lg border border-slate-200 object-cover">
                        <figcaption class="mt-1 text-center text-xs text-slate-500">Feed · 1080×1080</figcaption>
                    </figure>
                    <figure>
                        <img src="{{ $post->storyImageUrl() }}" alt="Story image for {{ $post->title }}" class="aspect-[9/16] w-full rounded-lg border border-slate-200 object-cover">
                        <figcaption class="mt-1 text-center text-xs text-slate-500">Story / Status · 1080×1920</figcaption>
                    </figure>
                </div>
            </x-ui.card>

            <x-ui.card title="Share kit" description="For your WhatsApp Status or Channel — WhatsApp doesn't allow apps to post there, so share it yourself in two taps.">
                <div
                    x-data="{
                        copied: false,
                        canShareFiles: false,
                        init() { this.canShareFiles = !! (navigator.canShare && navigator.share) },
                        async copyCaption() {
                            await navigator.clipboard.writeText(@js($caption));
                            this.copied = true; setTimeout(() => this.copied = false, 2000);
                        },
                        async share() {
                            try {
                                const blob = await (await fetch(@js($post->storyImageUrl()))).blob();
                                const file = new File([blob], 'story.jpg', { type: 'image/jpeg' });
                                if (navigator.canShare({ files: [file] })) {
                                    await navigator.share({ files: [file], text: @js($caption) });
                                    return;
                                }
                            } catch (e) {}
                            window.open('https://wa.me/?text=' + encodeURIComponent(@js($caption)), '_blank');
                        },
                    }"
                    class="space-y-3"
                >
                    <div class="grid grid-cols-2 gap-2">
                        <a href="{{ $post->storyImageUrl() }}" download="{{ \Illuminate\Support\Str::slug($post->title) }}-story.jpg"><x-ui.button variant="secondary" size="sm" class="w-full">Download story</x-ui.button></a>
                        <a href="{{ $post->imageUrl() }}" download="{{ \Illuminate\Support\Str::slug($post->title) }}-post.jpg"><x-ui.button variant="secondary" size="sm" class="w-full">Download post</x-ui.button></a>
                    </div>
                    <x-ui.button variant="secondary" size="sm" class="w-full" x-on:click="copyCaption">
                        <span x-show="! copied">Copy caption</span><span x-show="copied" x-cloak>Copied ✓</span>
                    </x-ui.button>
                    <x-ui.button size="sm" class="w-full" x-on:click="share">Share to WhatsApp</x-ui.button>
                    <ol class="list-decimal space-y-1 pl-5 text-xs text-slate-500">
                        <li>On your phone, tap <strong>Share to WhatsApp</strong> (or download the story image).</li>
                        <li>Choose <strong>My status</strong> or your Channel, paste the caption and post.</li>
                        <li>Customers who reply with <strong>{{ $post->refTag() }}</strong> are counted for this post.</li>
                    </ol>
                    @if ($shareLink)
                        <p class="break-all text-xs text-slate-400">Order link: {{ $shareLink }}</p>
                    @endif
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4 lg:col-span-3">
            <x-ui.card title="Caption">
                <textarea wire:model="caption" rows="8" @disabled(! $editable) class="block w-full rounded-lg border-slate-300 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-50" aria-label="Caption"></textarea>
                <x-ui.error :messages="$errors->get('caption')" class="mt-1.5" />
                <p class="mt-1.5 text-xs text-slate-500">Instagram stories don't show captions — the price is printed on the image instead. Keep the {{ $post->refTag() }} link so orders are tracked.</p>
                @if ($editable)
                    <div class="mt-3"><x-ui.button size="sm" variant="secondary" wire:click="saveCaption">Save caption</x-ui.button></div>
                @endif
            </x-ui.card>

            @if ($shop->social_enabled)
                <x-ui.card title="Post to Facebook & Instagram">
                    @if ($editable)
                        <form wire:submit="publish" class="space-y-4">
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach (PostSurface::cases() as $surface)
                                    <label @class(['flex items-center gap-2 rounded-lg border px-3 py-2.5 text-sm', 'border-slate-200 text-slate-700' => $connected[$surface->value], 'border-dashed border-slate-200 text-slate-400' => ! $connected[$surface->value]])>
                                        <input type="checkbox" wire:model="surfaces" value="{{ $surface->value }}" @disabled(! $connected[$surface->value]) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        {{ $surface->label() }}
                                        @unless ($connected[$surface->value])
                                            <span class="ml-auto text-xs">not connected</span>
                                        @endunless
                                    </label>
                                @endforeach
                            </div>
                            <x-ui.error :messages="$errors->get('surfaces')" />

                            <x-ui.field label="Schedule (optional)" name="scheduleAt" for="scheduleAt" help="Leave empty to post right away.">
                                <x-ui.input wire:model="scheduleAt" id="scheduleAt" type="datetime-local" min="{{ now()->format('Y-m-d\TH:i') }}" />
                            </x-ui.field>

                            <x-ui.button type="submit" wire:loading.attr="disabled">{{ $scheduleAt !== '' ? 'Schedule post' : 'Post now' }}</x-ui.button>
                        </form>
                    @endif

                    @if ($targets->isNotEmpty())
                        <div @class(['divide-y divide-slate-100', 'mt-5 border-t border-slate-100 pt-3' => $editable])>
                            @foreach ($targets as $target)
                                <div class="flex flex-col gap-1 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between" wire:key="target-{{ $target->id }}">
                                    <span class="font-medium text-slate-900">{{ $target->surface->label() }}</span>
                                    <div class="flex items-center gap-2">
                                        @if ($target->status === 'published')
                                            <x-ui.badge variant="success">Published</x-ui.badge>
                                            @if ($target->permalink)
                                                <a href="{{ $target->permalink }}" target="_blank" rel="noopener" class="text-xs font-medium text-brand-700 hover:text-brand-800">View →</a>
                                            @endif
                                            @if ($target->metric('reach') !== null)
                                                <span class="text-xs text-slate-500">{{ number_format($target->metric('reach')) }} reached</span>
                                            @endif
                                        @elseif ($target->status === 'failed')
                                            <x-ui.badge variant="danger" title="{{ $target->error }}">Failed</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="warning">{{ $post->status === PostStatus::Scheduled ? 'Scheduled' : 'Publishing…' }}</x-ui.badge>
                                        @endif
                                    </div>
                                </div>
                                @if ($target->status === 'failed' && $target->error)
                                    <p class="pb-2 text-xs text-red-700">{{ $target->error }}</p>
                                @endif
                            @endforeach
                        </div>
                        @if ($targets->contains('status', 'failed'))
                            <div class="mt-3"><x-ui.button size="sm" variant="secondary" wire:click="retryFailed">Retry failed</x-ui.button></div>
                        @endif
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
