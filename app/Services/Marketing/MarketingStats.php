<?php

namespace App\Services\Marketing;

use App\Enums\PostKind;
use App\Enums\SaleSource;
use App\Enums\WhatsAppOrderStatus;
use App\Models\CustomerReview;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\MetaAdCampaign;
use App\Models\Sale;
use App\Models\WhatsAppOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MarketingStats
{
    public function __construct(public readonly CarbonInterface $from, public readonly CarbonInterface $to)
    {
    }

    protected function range(): array
    {
        return [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()];
    }

    public function salesBySource(): Collection
    {
        $rows = Sale::query()
            ->whereBetween('created_at', $this->range())
            ->selectRaw('source, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('source')
            ->get()
            ->keyBy(fn ($row) => $row->source instanceof SaleSource ? $row->source->value : $row->source);

        return collect(SaleSource::cases())->mapWithKeys(fn (SaleSource $source) => [$source->value => [
            'label' => $source->label(),
            'orders' => (int) ($rows[$source->value]->orders ?? 0),
            'revenue' => (float) ($rows[$source->value]->revenue ?? 0),
        ]]);
    }

    public function whatsappFunnel(): array
    {
        $orders = WhatsAppOrder::query()
            ->whereBetween('created_at', $this->range())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (WhatsAppOrderStatus ...$statuses) => (int) collect($statuses)->sum(fn ($s) => $orders[$s->value] ?? 0);

        return [
            'carts' => (int) $orders->sum(),
            'submitted' => $count(WhatsAppOrderStatus::Pending, WhatsAppOrderStatus::Accepted, WhatsAppOrderStatus::Rejected),
            'pending' => $count(WhatsAppOrderStatus::Pending),
            'accepted' => $count(WhatsAppOrderStatus::Accepted),
            'rejected' => $count(WhatsAppOrderStatus::Rejected),
        ];
    }

    protected function acceptedOrdersInRange()
    {
        return WhatsAppOrder::query()
            ->where('status', WhatsAppOrderStatus::Accepted->value)
            ->whereNotNull('sale_id')
            ->whereBetween('decided_at', $this->range());
    }

    protected function revenueFor(Collection $saleIds): float
    {
        return $saleIds->isEmpty() ? 0.0 : (float) Sale::query()->whereIn('id', $saleIds)->sum('total');
    }

    public function attribution(): array
    {
        $orders = $this->acceptedOrdersInRange()->get(['id', 'sale_id', 'origin_ref', 'referral']);
        $refs = MarketingPost::query()->whereIn('ref_code', $orders->pluck('origin_ref')->filter()->unique())->pluck('kind', 'ref_code');

        $group = function (Collection $subset) {
            $activeSaleIds = Sale::query()->whereIn('id', $subset->pluck('sale_id'))->pluck('id');

            return ['orders' => $activeSaleIds->count(), 'revenue' => $this->revenueFor($activeSaleIds)];
        };

        $kindOf = fn ($order) => $order->origin_ref && isset($refs[$order->origin_ref])
            ? ($refs[$order->origin_ref] instanceof PostKind ? $refs[$order->origin_ref]->value : $refs[$order->origin_ref])
            : null;

        return [
            'posts' => $group($orders->filter(fn ($o) => in_array($kindOf($o), [PostKind::Product->value, PostKind::Review->value], true))),
            'qr' => $group($orders->filter(fn ($o) => $kindOf($o) === PostKind::QrCode->value)),
            'ads' => $group($orders->filter(fn ($o) => $kindOf($o) === null && ! empty($o->referral))),
            'direct' => $group($orders->filter(fn ($o) => $kindOf($o) === null && empty($o->referral))),
        ];
    }

    public function topPosts(int $limit = 10): Collection
    {
        $posts = MarketingPost::query()
            ->whereIn('kind', [PostKind::Product->value, PostKind::Review->value, PostKind::QrCode->value])
            ->where(fn ($q) => $q->whereBetween('published_at', $this->range())->orWhereBetween('created_at', $this->range()))
            ->with('targets')
            ->latest('id')
            ->limit(100)
            ->get();

        $ordersByRef = WhatsAppOrder::query()
            ->where('status', WhatsAppOrderStatus::Accepted->value)
            ->whereIn('origin_ref', $posts->pluck('ref_code'))
            ->get(['origin_ref', 'sale_id'])
            ->groupBy('origin_ref');

        return $posts->map(function (MarketingPost $post) use ($ordersByRef) {
            $saleIds = Sale::query()->whereIn('id', ($ordersByRef[$post->ref_code] ?? collect())->pluck('sale_id'))->pluck('id');

            return [
                'post' => $post,
                'reach' => $post->targets->sum(fn (MarketingPostTarget $t) => $t->metric('reach') ?? 0),
                'engagement' => $post->targets->sum(fn (MarketingPostTarget $t) => ($t->metric('likes') ?? 0) + ($t->metric('comments') ?? 0) + ($t->metric('shares') ?? 0) + ($t->metric('saved') ?? 0) + ($t->metric('replies') ?? 0)),
                'orders' => $saleIds->count(),
                'revenue' => $this->revenueFor($saleIds),
            ];
        })->sortByDesc(fn ($row) => [$row['orders'], $row['reach']])->take($limit)->values();
    }

    public static function adResults(MetaAdCampaign $campaign): array
    {
        $saleIds = $campaign->meta_ad_id
            ? WhatsAppOrder::query()
                ->where('status', WhatsAppOrderStatus::Accepted->value)
                ->whereNotNull('sale_id')
                ->where('referral->source_id', $campaign->meta_ad_id)
                ->pluck('sale_id')
            : collect();

        $activeSaleIds = $saleIds->isEmpty() ? collect() : Sale::query()->whereIn('id', $saleIds)->pluck('id');
        $revenue = $activeSaleIds->isEmpty() ? 0.0 : (float) Sale::query()->whereIn('id', $activeSaleIds)->sum('total');
        $spend = $campaign->metric('spend');

        return [
            'orders' => $activeSaleIds->count(),
            'revenue' => $revenue,
            'spend' => $spend,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
        ];
    }

    public function adTotals(): array
    {
        $campaigns = MetaAdCampaign::query()
            ->whereNotNull('launched_at')
            ->where('start_at', '<=', $this->range()[1])
            ->where('end_at', '>=', $this->range()[0])
            ->get();

        $results = $campaigns->map(fn (MetaAdCampaign $c) => self::adResults($c));
        $spend = (float) $results->sum('spend');
        $revenue = (float) $results->sum('revenue');

        return [
            'campaigns' => $campaigns->count(),
            'spend' => $spend,
            'orders' => (int) $results->sum('orders'),
            'revenue' => $revenue,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
        ];
    }

    public function socialTotals(): array
    {
        $targets = MarketingPostTarget::query()
            ->where('status', 'published')
            ->whereBetween('published_at', $this->range())
            ->get(['surface', 'metrics']);

        return [
            'posts' => $targets->count(),
            'reach' => $targets->sum(fn ($t) => $t->metric('reach') ?? 0),
            'engagement' => $targets->sum(fn ($t) => ($t->metric('likes') ?? 0) + ($t->metric('comments') ?? 0) + ($t->metric('shares') ?? 0) + ($t->metric('saved') ?? 0) + ($t->metric('replies') ?? 0)),
        ];
    }

    public function reviews(): array
    {
        $reviews = CustomerReview::query()->where('status', 'complete')->whereBetween('created_at', $this->range());

        return [
            'count' => (clone $reviews)->count(),
            'average' => round((float) (clone $reviews)->avg('rating'), 1),
        ];
    }
}
