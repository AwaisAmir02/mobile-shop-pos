<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Warranty check at the counter: find a sold unit by its IMEI / serial (all of
 * it, or its last digits) or by the invoice number, and see whether it is
 * still covered. Reads only what was frozen on the sale line when it was sold.
 */
new #[Layout('layouts.app')] #[Title('Warranty Check')] class extends Component
{
    public const MIN_DIGITS = 4;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->sellsDevices(), 404);
    }

    protected function term(): string
    {
        return trim($this->search);
    }

    protected function invoiceId(string $term): ?int
    {
        if (preg_match('/^inv-?0*(\d{1,9})$/i', $term, $m)) {
            return (int) $m[1];
        }

        // A short plain number is an invoice number; a long one is an IMEI.
        return ctype_digit($term) && strlen($term) <= 6 ? (int) $term : null;
    }

    /** @return Collection<int, SaleItem> */
    protected function matches(): Collection
    {
        $term = $this->term();

        if (mb_strlen($term) < self::MIN_DIGITS && $this->invoiceId($term) === null) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\');

        $query = SaleItem::query()
            ->with(['sale' => fn ($q) => $q->withoutGlobalScope(ExcludesCancelledSalesScope::class)->with('customer'), 'product'])
            ->where(function ($q) use ($term, $like) {
                if (mb_strlen($term) >= self::MIN_DIGITS) {
                    // The serial frozen at sale, or the product's IMEI for sales made before that.
                    $q->where('serial_number', 'like', $like)
                        ->orWhere(fn ($old) => $old->whereNull('serial_number')->whereIn('product_id', Product::query()->where('details->imei', 'like', $like)->select('id')));
                }

                if (($id = $this->invoiceId($term)) !== null) {
                    $q->orWhere('sale_id', $id);
                }
            })
            ->latest('id')
            ->limit(25);

        return $query->get()->filter(fn (SaleItem $item) => $item->sale !== null)->values();
    }

    /** IMEIs that match but haven't been sold: worth saying so rather than "nothing found". */
    protected function unsoldMatches(Collection $sold): Collection
    {
        $term = $this->term();

        if (mb_strlen($term) < self::MIN_DIGITS) {
            return collect();
        }

        return Product::query()
            ->where('details->imei', 'like', '%'.addcslashes($term, '%_\\'))
            ->whereNotIn('id', $sold->pluck('product_id')->filter())
            ->limit(10)
            ->get();
    }

    public static function status(SaleItem $item, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        if ($item->sale->isCancelled()) {
            return ['label' => 'Sale cancelled', 'variant' => 'neutral', 'detail' => 'This sale was cancelled, so it carries no warranty.'];
        }

        if (! $item->hasWarranty()) {
            return ['label' => 'No warranty', 'variant' => 'neutral', 'detail' => 'No warranty was recorded when this was sold.'];
        }

        $ends = $item->warrantyEndsOn($item->sale->created_at);

        if ($ends->gte($today)) {
            $days = (int) $today->diffInDays($ends) + 1;

            return ['label' => 'Under warranty', 'variant' => 'success', 'detail' => $days.' '.\Illuminate\Support\Str::plural('day', $days).' left · ends '.$ends->format('d M Y')];
        }

        return ['label' => 'Expired', 'variant' => 'danger', 'detail' => 'Ended '.$ends->format('d M Y').' ('.$ends->diffForHumans($today, ['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW]).')'];
    }

    public function with(): array
    {
        $results = $this->matches();

        return [
            'results' => $results,
            'unsold' => $this->unsoldMatches($results),
            'searched' => $this->term() !== '',
            'tooShort' => $this->term() !== '' && mb_strlen($this->term()) < self::MIN_DIGITS && $this->invoiceId($this->term()) === null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Check a claim by IMEI or invoice number">
            Warranty Check
        </x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="sales" label="Sales sections" />

    <x-ui.card class="mb-6">
        <x-ui.field label="IMEI, serial or invoice number" name="search" for="warrantySearch" help="The full IMEI or just its last few digits, or an invoice number such as INV-000123.">
            <x-ui.input wire:model.live.debounce.300ms="search" id="warrantySearch" inputmode="search" autocomplete="off" autofocus placeholder="e.g. 356789104523117" />
        </x-ui.field>
    </x-ui.card>

    @if (! $searched)
        <x-ui.empty-state title="Type an IMEI or invoice number" description="Warranties are taken from what was set on the product when the unit was sold. Set a product's warranty on the Products screen." />
    @elseif ($tooShort)
        <x-ui.empty-state title="Keep typing" description="Enter at least {{ $this::MIN_DIGITS }} digits of the IMEI, or the invoice number." />
    @elseif ($results->isEmpty() && $unsold->isEmpty())
        <x-ui.empty-state title="Nothing sold matches “{{ $search }}”" description="Check the number, or try the invoice number from the customer's receipt." />
    @else
        <div class="space-y-3" data-warranty-results>
            @foreach ($results as $item)
                @php($status = $this::status($item))
                <x-ui.card wire:key="warranty-item-{{ $item->id }}" data-warranty-result="{{ \Illuminate\Support\Str::slug($status['label']) }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-base font-bold text-ink">{{ $item->product_name }}</p>
                            <p class="text-sm text-ink-faint">IMEI / serial: <span class="font-semibold text-ink-muted tabular-nums">{{ $item->serial_number ?? ($item->product?->details['imei'] ?? '—') }}</span></p>
                        </div>
                        <div class="text-right">
                            <x-ui.badge :variant="$status['variant']" class="text-sm">{{ $status['label'] }}</x-ui.badge>
                            <p class="mt-1 text-sm font-semibold text-ink">{{ $status['detail'] }}</p>
                        </div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-line pt-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-ink-faint">Invoice</dt>
                            <dd><a href="{{ route('sales.show', $item->sale) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $item->sale->invoiceNumber() }}</a></dd>
                        </div>
                        <div>
                            <dt class="text-ink-faint">Sold on</dt>
                            <dd class="font-semibold text-ink">{{ $item->sale->created_at->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-faint">Customer</dt>
                            <dd class="font-semibold text-ink">{{ $item->sale->customer?->name ?? 'Walk-in' }}
                                @if ($item->sale->customer?->phone)
                                    <span class="block text-xs font-normal text-ink-faint">{{ $item->sale->customer->phone }}</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-ink-faint">Warranty</dt>
                            <dd class="font-semibold text-ink">{{ $item->hasWarranty() ? $item->warranty_months.' months · '.($item->warranty_type?->label() ?? 'Warranty') : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-faint">Price</dt>
                            <dd class="font-semibold tabular-nums text-ink">Rs {{ number_format($item->line_total, 2) }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endforeach

            @foreach ($unsold as $product)
                <x-ui.card wire:key="warranty-unsold-{{ $product->id }}" data-warranty-result="unsold">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-base font-bold text-ink">{{ $product->name }}</p>
                            <p class="text-sm text-ink-faint">IMEI / serial: <span class="font-semibold text-ink-muted tabular-nums">{{ $product->details['imei'] }}</span></p>
                        </div>
                        <x-ui.badge variant="info">{{ (float) $product->stock_quantity > 0 ? 'In stock, not sold' : 'Not sold here' }}</x-ui.badge>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
