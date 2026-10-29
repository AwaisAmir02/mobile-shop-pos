<?php

namespace App\Services\Marketing;

use App\Enums\ChannelProvider;
use App\Enums\LimitKey;
use App\Enums\PostKind;
use App\Enums\PostStatus;
use App\Enums\PostSurface;
use App\Jobs\PublishMarketingPostTarget;
use App\Models\CustomerReview;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Services\Billing\Entitlements;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostComposer
{
    public function __construct(protected CardRenderer $cards)
    {
    }

    public static function whatsappNumber(Shop $shop): ?string
    {
        $connection = ShopChannelConnection::activeFor($shop->id, ChannelProvider::WhatsApp);
        $display = $connection?->meta['display_phone_number'] ?? null;

        return PhoneNumber::normalize($display) ?? PhoneNumber::normalize($shop->phone);
    }

    public static function whatsappLink(Shop $shop, string $text): ?string
    {
        $number = self::whatsappNumber($shop);

        return $number ? 'https://wa.me/'.$number.'?text='.rawurlencode($text) : null;
    }

    public static function prettyNumber(?string $digits): ?string
    {
        if ($digits === null) {
            return null;
        }

        return str_starts_with($digits, '92') && strlen($digits) === 12
            ? '+92 '.substr($digits, 2, 3).' '.substr($digits, 5)
            : '+'.$digits;
    }

    public function footerFor(Shop $shop): string
    {
        $number = self::prettyNumber(self::whatsappNumber($shop));

        return $number ? 'Order on WhatsApp: '.$number : $shop->invoiceDisplayName();
    }

    public function defaultProductCaption(Shop $shop, Product $product, string $refTag): string
    {
        $link = self::whatsappLink($shop, 'Hi! I want to order '.$product->name.' '.$refTag);

        return trim(implode("\n", array_filter([
            '✨ '.$product->name,
            '💰 Rs '.number_format($product->currentSellingPrice()),
            $product->stock_quantity > 0 && $product->stock_quantity <= 3 ? '⚡ Only '.$product->stock_quantity.' left!' : null,
            '',
            $link ? '📲 Order on WhatsApp: '.$link : '📍 Visit '.$shop->invoiceDisplayName(),
        ], fn ($line) => $line !== null)));
    }

    public function defaultReviewCaption(Shop $shop, CustomerReview $review, string $refTag): string
    {
        $link = self::whatsappLink($shop, 'Hi! '.$refTag);

        return trim(implode("\n", array_filter([
            $review->stars().' '.$review->publicName().' says:',
            $review->comment ? '“'.$review->comment.'”' : null,
            '',
            'Thank you for shopping with '.$shop->invoiceDisplayName().'! 🙏',
            $link ? '📲 Order on WhatsApp: '.$link : null,
        ], fn ($line) => $line !== null)));
    }

    public function createProductPost(Shop $shop, int $userId, Product $product): MarketingPost
    {
        abort_unless($product->shop_id === $shop->id, 404);
        app(Entitlements::class)->ensureCanAdd($shop, LimitKey::MarketingPostsPerMonth);

        return DB::transaction(function () use ($shop, $userId, $product) {
            $post = MarketingPost::create([
                'shop_id' => $shop->id,
                'created_by' => $userId,
                'kind' => PostKind::Product,
                'title' => $product->name,
                'ref_code' => MarketingPost::newRefCode(),
                'status' => PostStatus::Draft,
            ]);

            $post->products()->attach($product->id, ['position' => 0]);

            $cards = $this->cards->productCards($shop, $product, $this->footerFor($shop), $this->directory($post));
            $post->update([
                'caption' => $this->defaultProductCaption($shop, $product, $post->refTag()),
                'image_path' => $cards['square'],
                'story_image_path' => $cards['story'],
            ]);

            return $post;
        });
    }

    public function createReviewPost(Shop $shop, int $userId, CustomerReview $review): MarketingPost
    {
        if ($review->shop_id !== $shop->id || ! $review->isPublishable()) {
            throw new RuntimeException('Only reviews the customer agreed to share can be posted.');
        }

        app(Entitlements::class)->ensureCanAdd($shop, LimitKey::MarketingPostsPerMonth);

        return DB::transaction(function () use ($shop, $userId, $review) {
            $post = MarketingPost::create([
                'shop_id' => $shop->id,
                'created_by' => $userId,
                'kind' => PostKind::Review,
                'title' => 'Review by '.$review->publicName(),
                'ref_code' => MarketingPost::newRefCode(),
                'status' => PostStatus::Draft,
                'customer_review_id' => $review->id,
            ]);

            $cards = $this->cards->reviewCards($shop, $review, $this->footerFor($shop), $this->directory($post));
            $post->update([
                'caption' => $this->defaultReviewCaption($shop, $review, $post->refTag()),
                'image_path' => $cards['square'],
                'story_image_path' => $cards['story'],
            ]);

            return $post;
        });
    }

    public function createQrCode(Shop $shop, int $userId, string $title): MarketingPost
    {
        return MarketingPost::create([
            'shop_id' => $shop->id,
            'created_by' => $userId,
            'kind' => PostKind::QrCode,
            'title' => $title,
            'ref_code' => MarketingPost::newRefCode(),
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function schedule(MarketingPost $post, array $surfaces, ?CarbonInterface $at, string $caption): MarketingPost
    {
        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed], true)) {
            throw new RuntimeException('This post has already been published.');
        }

        $surfaces = array_map(fn ($s) => $s instanceof PostSurface ? $s : PostSurface::from($s), $surfaces);

        if ($surfaces === []) {
            throw new RuntimeException('Pick at least one place to post.');
        }

        return DB::transaction(function () use ($post, $surfaces, $at, $caption) {
            $post->targets()->whereNotIn('status', ['published'])->delete();

            foreach ($surfaces as $surface) {
                $connection = ShopChannelConnection::activeFor($post->shop_id, $surface->provider())
                    ?? throw new RuntimeException($surface->provider()->label().' is not connected.');

                MarketingPostTarget::updateOrCreate(
                    ['marketing_post_id' => $post->id, 'surface' => $surface->value],
                    ['shop_id' => $post->shop_id, 'shop_channel_connection_id' => $connection->id, 'status' => 'pending', 'error' => null],
                );
            }

            $post->update([
                'caption' => $caption,
                'scheduled_at' => $at,
                'status' => $at !== null && $at->isFuture() ? PostStatus::Scheduled : PostStatus::Publishing,
            ]);

            if ($post->status === PostStatus::Publishing) {
                DB::afterCommit(fn () => $this->dispatchTargets($post));
            }

            return $post;
        });
    }

    public function dispatchTargets(MarketingPost $post): void
    {
        MarketingPostTarget::withoutGlobalScopes()
            ->where('marketing_post_id', $post->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->each(fn (int $id) => PublishMarketingPostTarget::dispatch($id));
    }

    protected function directory(MarketingPost $post): string
    {
        return 'shop-'.$post->shop_id.'/marketing/'.$post->id;
    }
}
