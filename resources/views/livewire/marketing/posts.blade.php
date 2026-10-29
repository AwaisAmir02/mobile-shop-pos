<?php

use App\Enums\PostKind;
use App\Livewire\Concerns\Toasts;
use App\Models\MarketingPost;
use App\Models\Product;
use App\Services\Marketing\PostComposer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Posts & Share kit')] class extends Component
{
    use Toasts, WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $shop = Auth::user()->shop;
        abort_unless($shop?->whatsapp_enabled || $shop?->social_enabled, 403);
    }

    public function createFromProduct(int $productId, PostComposer $composer): void
    {
        $product = Product::findOrFail($productId);

        try {
            $post = $composer->createProductPost(Auth::user()->shop, Auth::id(), $product);
        } catch (\Throwable $e) {
            report($e);
            $this->toastError('Could not create the post: '.$e->getMessage());

            return;
        }

        $this->redirectRoute('marketing.posts.show', $post, navigate: true);
    }

    public function with(): array
    {
        return [
            'results' => $this->search !== ''
                ? Product::query()->where('name', 'like', '%'.$this->search.'%')->where('type', '!=', 'sim')->orderByDesc('stock_quantity')->orderBy('name')->limit(8)->get()
                : collect(),
            'posts' => MarketingPost::query()
                ->whereIn('kind', [PostKind::Product->value, PostKind::Review->value])
                ->with('targets')
                ->latest('id')
                ->paginate(15),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.posts" />

    <x-ui.card title="Promote a product" description="Pick a product — we'll make a feed image, a story image and a caption with a WhatsApp order link. Then post it to Facebook/Instagram or share it to your WhatsApp Status yourself.">
        <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search your products…" aria-label="Search products" />
        @if ($results->isNotEmpty())
            <div class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($results as $product)
                    <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="pick-{{ $product->id }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" />
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $product->name }}</p>
                                <p class="text-xs text-slate-500">Rs {{ number_format($product->currentSellingPrice()) }} · {{ $product->stock_quantity }} in stock</p>
                            </div>
                        </div>
                        <x-ui.button size="sm" wire:click="createFromProduct({{ $product->id }})" wire:loading.attr="disabled">Create post</x-ui.button>
                    </div>
                @endforeach
            </div>
        @elseif ($search !== '')
            <p class="mt-3 text-sm text-slate-500">No products match "{{ $search }}".</p>
        @endif
    </x-ui.card>

    <div class="mt-6">
        @if ($posts->isEmpty())
            <x-ui.empty-state title="No posts yet" description="Posts you create appear here with their status on each platform." />
        @else
            <x-ui.table :headers="['Post', 'Type', 'Placements', 'Status', 'Created', '']">
                @foreach ($posts as $post)
                    <x-ui.table-row wire:key="post-{{ $post->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">
                            {{ $post->title }}
                            <div class="text-xs text-slate-400">{{ $post->refTag() }}</div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $post->kind->label() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $post->targets->map(fn ($t) => $t->surface->label())->implode(', ') ?: '—' }}</x-ui.table-cell>
                        <x-ui.table-cell><x-ui.badge :variant="$post->status->badge()">{{ $post->status->label() }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell>{{ $post->created_at->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            <a href="{{ route('marketing.posts.show', $post) }}" wire:navigate><x-ui.button size="sm" variant="secondary">Open</x-ui.button></a>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $posts->links() }}</div>
        @endif
    </div>
</div>
