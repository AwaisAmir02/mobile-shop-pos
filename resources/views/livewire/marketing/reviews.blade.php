<?php

use App\Livewire\Concerns\Toasts;
use App\Models\CustomerReview;
use App\Services\Marketing\PostComposer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customer Reviews')] class extends Component
{
    use Toasts, WithPagination;

    public function mount(): void
    {
        $shop = Auth::user()->shop;
        abort_unless($shop?->whatsapp_enabled || $shop?->social_enabled, 403);
    }

    public function createPost(int $reviewId, PostComposer $composer): void
    {
        $review = CustomerReview::findOrFail($reviewId);

        try {
            $post = $composer->createReviewPost(Auth::user()->shop, Auth::id(), $review);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->redirectRoute('marketing.posts.show', $post, navigate: true);
    }

    public function with(): array
    {
        return [
            'reviews' => CustomerReview::query()
                ->where('status', 'complete')
                ->with(['contact', 'posts'])
                ->latest('id')
                ->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.reviews" />

    <p class="mb-4 text-sm text-slate-500">Customers are asked for a review on WhatsApp a day after their order is delivered. Only reviews where the customer said <strong>yes, share it</strong> can be turned into a post — and only their first name is shown.</p>

    @if ($reviews->isEmpty())
        <x-ui.empty-state title="No reviews yet" description="Mark WhatsApp orders as Delivered and review requests go out automatically the next day." />
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($reviews as $review)
                <x-ui.card wire:key="review-{{ $review->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-lg text-amber-500" aria-label="{{ $review->rating }} out of 5">{{ $review->stars() }}</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ $review->display_name ?? 'Customer' }}</p>
                            <p class="text-xs text-slate-500">{{ $review->created_at->format('d M Y') }} · +{{ $review->contact?->wa_id }}</p>
                        </div>
                        @if ($review->publish_consent)
                            <x-ui.badge variant="success">Can share</x-ui.badge>
                        @else
                            <x-ui.badge>Private</x-ui.badge>
                        @endif
                    </div>
                    @if ($review->comment)
                        <p class="mt-3 text-sm text-slate-700">“{{ $review->comment }}”</p>
                    @endif
                    <div class="mt-4 flex items-center gap-2">
                        @if ($review->isPublishable())
                            <x-ui.button size="sm" wire:click="createPost({{ $review->id }})" wire:loading.attr="disabled">Turn into a post</x-ui.button>
                        @endif
                        @foreach ($review->posts as $post)
                            <a href="{{ route('marketing.posts.show', $post) }}" wire:navigate class="text-xs font-medium text-brand-700 hover:text-brand-800">Post {{ $post->refTag() }} →</a>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4">{{ $reviews->links() }}</div>
    @endif
</div>
