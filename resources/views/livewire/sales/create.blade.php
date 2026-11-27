<?php

use App\Actions\ConsumeStockFifo;
use App\Actions\CreateSale;
use App\Actions\Promotions;
use App\Enums\QuantityUnit;
use App\Enums\SaleStatus;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\PromptsForMeasuredQuantity;
use App\Livewire\Concerns\SellsOnline;
use App\Livewire\Concerns\SellsServices;
use App\Livewire\Concerns\SellsWholesale;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use App\Support\InstallmentSchedule;
use App\Support\SaleCartMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('New Sale')] class extends Component
{
    use PicksPaymentMethod, PromptsForMeasuredQuantity, SellsOnline, SellsServices, SellsWholesale, Toasts, \Livewire\WithFileUploads;

    /** Register details for prescription medicines in the cart (App\Actions\Prescriptions). */
    public array $prescription = [];
    public $prescriptionPhoto = null;

    const MAX_LINE_PRICE = 9999999.99;

    public string $search = '';
    public array $cart = [];
    public string $invoiceDiscount = '0';
    public string $customerId = '';
    public string $amountPaidNow = '';
    public string $saleDate = '';
    public ?int $pickerGroupId = null;

    // Instalments: amountPaidNow is the down payment; the rest is split monthly.
    public bool $onInstallments = false;
    public string $installmentCount = '3';
    public string $firstDueDate = '';

    // Trade-in (phone shops): the customer's used phone as part of the payment.
    // amountPaidNow is then the money part; blank still means "the rest in full".
    public bool $withTradeIn = false;
    public array $tradeIn = [];

    // Store credit: part of the sale paid from the chosen customer's credit.
    // Blank amount means as much as covers the rest, up to what they hold.
    public bool $useCredit = false;
    public string $creditAmount = '';

    // Exchange: opened from a sale's receipt. What comes back from that sale
    // pays for this one first (App\Actions\Exchanges).
    public ?int $exchangeSaleId = null;

    /** @var array<string, string> by SaleReturns::groups() key */
    public array $exchangeQuantities = [];

    /** @var array<string, string> restock or damaged */
    public array $exchangeDispositions = [];

    /** Where a leftover goes when the new items cost less: cash or credit. */
    public string $exchangeRefundTo = 'cash';

    public string $exchangeLateReason = '';

    public function mount(): void
    {
        $this->openExchange(request()->query('exchange'));
        $this->saleDate = now()->toDateString();
        $this->paymentMethod = 'cash';
        $this->firstDueDate = now()->addMonthNoOverflow()->toDateString();
        $this->tradeIn = self::blankTradeIn();
        $this->prescription = \App\Actions\Prescriptions::blank();
        $this->startOnline();
        $this->startServices();
    }

    /** @return array<int, array<string, mixed>> cart lines the shop marked Prescription only or Controlled */
    public function prescriptionLines(): array
    {
        return array_values(array_filter($this->cart, fn (array $item) => ! empty($item['rx'])));
    }

    /** What CreateSale writes in the register, or null when nothing in the cart needs it. */
    protected function prescriptionDetails(): ?array
    {
        return $this->prescriptionLines() !== [] ? $this->prescription + ['photo' => $this->prescriptionPhoto] : null;
    }

    protected static function blankTradeIn(): array
    {
        return ['seller_name' => '', 'seller_cnic' => '', 'brand' => '', 'model' => '', 'imei' => '', 'condition' => 'good', 'battery_health' => '', 'pta' => '', 'has_box' => false, 'has_charger' => false, 'price' => '', 'selling_price' => ''];
    }

    public function removeTradeIn(): void
    {
        $this->withTradeIn = false;
        $this->tradeIn = self::blankTradeIn();
        $this->resetValidation(array_map(fn ($key) => 'tradeIn.'.$key, array_keys(self::blankTradeIn())));
    }

    /** What the traded-in phone counts for, while one is being added. */
    public function tradeInValue(): float
    {
        return $this->withTradeIn && $this->offersInstallments() && is_numeric($this->tradeIn['price'] ?? null) ? round(max(0.0, (float) $this->tradeIn['price']), 2) : 0.0;
    }

    /** The chosen customer's store credit that can be used today. */
    public function creditAvailable(): float
    {
        return ctype_digit($this->customerId) ? \App\Actions\StoreCredit::spendable((int) $this->customerId) : 0.0;
    }

    /** What the store credit pays, while it's being used. */
    public function creditValue(): float
    {
        if (! $this->useCredit || ($available = $this->creditAvailable()) <= 0) {
            return 0.0;
        }

        $room = round(max(0.0, $this->total() - $this->tradeInValue() - $this->exchangeValue()), 2);
        $wanted = is_numeric($this->creditAmount) ? max(0.0, (float) $this->creditAmount) : $room;

        return round(min($wanted, $available, $room), 2);
    }

    /** What's left to pay after the trade-in and store credit. */
    public function toPay(): float
    {
        return round(max(0.0, $this->total() - $this->tradeInValue() - $this->exchangeValue() - $this->creditValue() - $this->servicePrepaidValue()), 2);
    }

    /** Starts exchange mode for a sale this person may take goods back from. */
    protected function openExchange(mixed $saleId): void
    {
        if (! ctype_digit((string) $saleId) || ! Auth::user()->hasAccessTo('sales-returns')) {
            return;
        }

        $sale = Sale::find((int) $saleId);

        if ($sale === null) {
            return;
        }

        $this->exchangeSaleId = $sale->id;
        $this->customerId = $sale->customer_id !== null ? (string) $sale->customer_id : '';
        $this->exchangeRefundTo = Auth::user()->shop->cash_refunds_allowed ? \App\Actions\SaleReturns::REFUND_CASH : \App\Actions\SaleReturns::REFUND_CREDIT;

        foreach (array_keys(\App\Actions\SaleReturns::groups($sale)) as $key) {
            $this->exchangeQuantities[$key] = '';
            $this->exchangeDispositions[$key] = 'restock';
        }
    }

    protected function exchangeSale(): ?Sale
    {
        return $this->exchangeSaleId !== null ? Sale::find($this->exchangeSaleId) : null;
    }

    protected function exchangeLines(): array
    {
        return collect($this->exchangeQuantities)->map(fn ($quantity, $key) => [
            'quantity' => $quantity,
            'disposition' => $this->exchangeDispositions[$key] ?? 'restock',
        ])->all();
    }

    /** What the goods coming back are due back: their value less anything still owed on the old sale. */
    public function exchangeDueBack(): float
    {
        $sale = $this->exchangeSale();

        return $sale ? \App\Actions\SaleReturns::refundableFor($sale, $this->exchangeLines()) : 0.0;
    }

    /** The part of it that pays for this sale. */
    public function exchangeValue(): float
    {
        return round(min($this->exchangeDueBack(), max(0.0, $this->total() - $this->tradeInValue())), 2);
    }

    /** What's left over for the customer when the new items cost less. */
    public function exchangeLeftover(): float
    {
        return round(max(0.0, $this->exchangeDueBack() - $this->exchangeValue()), 2);
    }

    public function closeExchange(): void
    {
        $this->reset(['exchangeSaleId', 'exchangeQuantities', 'exchangeDispositions', 'exchangeLateReason']);
    }

    protected function offersInstallments(): bool
    {
        // An exchange is kept simple: no instalments and no trade-in on top.
        if ($this->exchangeSaleId !== null) {
            return false;
        }

        return (bool) Auth::user()->shop?->sellsDevices();
    }

    /** The plan as it would be saved, or [] while the inputs don't make one yet. */
    protected function installmentPlan(): array
    {
        if (! $this->onInstallments || empty($this->cart) || ! ctype_digit($this->installmentCount) || $this->firstDueDate === '') {
            return [];
        }

        try {
            return InstallmentSchedule::plan($this->toPay() - (float) ($this->amountPaidNow ?: 0), (int) $this->installmentCount, Carbon::parse($this->firstDueDate));
        } catch (ValidationException|\Carbon\Exceptions\InvalidFormatException) {
            return [];
        }
    }

    public function updatedSearch(): void
    {
        $this->pickerGroupId = null;
    }

    public function openVariantPicker(int $groupId): void
    {
        $this->pickerGroupId = ProductGroup::findOrFail($groupId)->id;
    }

    public function closeVariantPicker(): void
    {
        $this->pickerGroupId = null;
    }

    /**
     * By name, or by generic (salt) name. A medicine whose brand matches the
     * search also brings in every in-stock brand with the same salt and
     * strength, so an out-of-stock brand still shows what can replace it.
     */
    protected function searchMatches()
    {
        $term = "%{$this->search}%";

        return Product::query()
            ->where('stock_quantity', '>', 0)
            ->where(fn ($query) => $query
                ->where('name', 'like', $term)
                ->orWhere('generic_name', 'like', $term)
                ->orWhereIn('generic_name', Product::query()->select('generic_name')->whereNotNull('generic_name')->where('name', 'like', $term)));
    }

    /**
     * What each collapsed group shows. A group with one matching variant is
     * left out: that variant is listed directly, under its full name.
     *
     * @return array<int, array{name: string, count: int, stock: int|float, unit: QuantityUnit, fromPrice: float}> keyed by group id
     */
    protected function groupEntries($results): array
    {
        $groupIds = $results->pluck('product_group_id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return [];
        }

        $matched = $this->searchMatches()->whereIn('product_group_id', $groupIds)->selectRaw('product_group_id, COUNT(*) as matched')->groupBy('product_group_id')->pluck('matched', 'product_group_id');
        $collapsing = $matched->filter(fn ($count) => (int) $count > 1)->keys();
        $inStock = Product::query()->whereIn('product_group_id', $collapsing)->where('stock_quantity', '>', 0)->get();
        $prices = Product::sellingPricesFor($inStock);
        $names = ProductGroup::whereIn('id', $collapsing)->pluck('name', 'id');

        return $inStock->groupBy('product_group_id')->map(fn ($variants, $groupId) => [
            'name' => $names[$groupId],
            'count' => $variants->count(),
            'stock' => Quantity::sum($variants->pluck('stock_quantity')),
            'unit' => $variants->first()->unit,
            'fromPrice' => min(array_intersect_key($prices, $variants->keyBy('id')->all())),
        ])->all();
    }

    /** Every variant of the open group, so the cashier can see which sizes have run out. */
    protected function pickerVariants()
    {
        if ($this->pickerGroupId === null) {
            return null;
        }

        $group = ProductGroup::with('variants')->find($this->pickerGroupId);

        if ($group === null) {
            return null;
        }

        $variants = $group->variants->whereNull('archived_at')->values();
        $prices = Product::sellingPricesFor($variants);

        return [
            'name' => $group->name,
            'rows' => $variants->map(fn (Product $variant) => ['variant' => $variant, 'price' => $prices[$variant->id]]),
        ];
    }

    public function updatedCustomerId(): void
    {
        $this->useCredit = false;
        $this->creditAmount = '';

        if ($this->customerId === '__create__') {
            $this->customerId = '';
            $this->dispatch('open-modal', name: 'quick-create-customer');
        }

        $this->wholesaleCustomerChanged();
        $this->servicesCustomerChanged();
    }

    #[On('customer-created')]
    public function onCustomerCreated(int $customerId): void
    {
        $this->customerId = (string) $customerId;
        $this->wholesaleCustomerChanged();
    }

    /**
     * Quick-pick tiles for an empty cart: this shop's best sellers over the last
     * 30 days that are in stock now. Read-only; tapping a tile calls addToCart
     * like a search result does. Sale and SaleItem are shop- and branch-scoped.
     */
    protected function quickPicks()
    {
        $soldIds = SaleItem::query()
            ->whereNotNull('product_id')
            ->whereHas('sale', fn ($query) => $query
                ->where('status', '!=', SaleStatus::Cancelled->value)
                ->where('created_at', '>=', now()->subDays(30)))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as sold')
            ->orderByDesc('sold')
            ->limit(24)
            ->pluck('product_id')
            ->values();

        if ($soldIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $soldIds)
            ->where('stock_quantity', '>', 0)
            ->whereNull('archived_at')
            ->get()
            ->sortBy(fn (Product $product) => $soldIds->search($product->id))
            ->take(8)
            ->values();
    }

    public function with(): array
    {
        $results = $this->search !== ''
            ? $this->searchMatches()
                ->oneRowPerGroup($this->searchMatches())
                ->orderBy('name')
                ->limit(8)
                ->get()
            : collect();

        return [
            'results' => $results,
            'groupEntries' => $this->groupEntries($results),
            'picker' => $this->pickerVariants(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentAccounts' => $this->paymentAccounts(),
            'barcodeEnabled' => Auth::user()->canUseBarcodes(),
            'quickPicks' => empty($this->cart) && $this->search === '' ? $this->quickPicks() : collect(),
            'promptProduct' => $this->quantityPromptId !== null ? Product::find($this->quantityPromptId) : null,
            'offersInstallments' => $this->offersInstallments(),
            'installmentPlan' => $this->installmentPlan(),
        ] + $this->wholesaleViewData() + $this->onlineViewData() + $this->servicesViewData();
    }

    public function addToCart(int $productId): void
    {
        $product = Product::findOrFail($productId);

        if ($product->stock_quantity <= 0) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        if ($product->unit->allowsDecimals()) {
            $this->search = '';
            $this->pickerGroupId = null;
            $this->promptForQuantity($product);

            return;
        }

        if (isset($this->cart[$productId])) {
            $newQuantity = Quantity::add($this->cart[$productId]['quantity'], 1);

            if ($newQuantity > $this->cart[$productId]['max_stock']) {
                $level = self::sellingLevel($this->cart[$productId]);
                $this->toastError($level !== null
                    ? 'Only '.Product::countLabel($level['name'], (int) $this->cart[$productId]['max_stock'])." of {$product->name} in stock."
                    : "Only {$product->stock_quantity} of {$product->name} in stock.");

                return;
            }

            $this->cart[$productId]['quantity'] = $newQuantity;
        } else {
            $this->cart[$productId] = $this->cartLineFor($product);
        }

        $this->search = '';
        $this->pickerGroupId = null;
    }

    protected function quantityLimitFor(Product $product): int|float
    {
        return $product->stock_quantity;
    }

    protected function cartLineFor(Product $product, int|float $quantity = 1): array
    {
        $line = [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->typeLabel(),
            'list_price' => (string) $product->currentSellingPrice(),
            'cost_price' => Auth::user()->hasAccessTo('sales-view-cost-price') ? $product->currentCostPrice() : null,
            'custom_price' => '',
            'quantity' => $quantity,
            'unit' => $product->unit->value,
            'discount' => '0',
            'max_stock' => $product->stock_quantity,
            // A product with a pack (carton, dozen…) can be sold a whole pack at a time.
            'pack' => $product->hasPack() ? ['name' => $product->pack_name, 'size' => $product->pack_size, 'price' => $product->packSellingPrice(), 'has_price' => $product->pack_price !== null] : null,
            'by_pack' => false,
            // And an outer pack made of several packs (a box of strips); size is in pieces.
            'outer' => $product->hasOuterPack() ? ['name' => $product->outer_pack_name, 'size' => $product->outerPackPieces(), 'packs' => (int) $product->outer_pack_size, 'price' => $product->outerPackSellingPrice(), 'has_price' => $product->outer_pack_price !== null || $product->pack_price !== null] : null,
            'by_outer' => false,
            // Prescription only / Controlled: goes in the prescription register.
            'rx' => $product->rx_control,
        ];

        // Wholesale businesses: the customer's trade prices and any running scheme (SellsWholesale).
        return $this->wholesaleCartLine($line, $product);
    }

    /** The pack a cart line is being sold in: its outer pack, its pack, or null for pieces. */
    protected static function sellingLevel(array $item): ?array
    {
        return match (true) {
            ! empty($item['by_outer']) && ! empty($item['outer']) => $item['outer'],
            ! empty($item['by_pack']) && ! empty($item['pack']) => $item['pack'],
            default => null,
        };
    }

    /** Switches a cart line between loose pieces, whole packs and whole outer packs. */
    public function sellAs(int $productId, string $mode): void
    {
        $item = $this->cart[$productId] ?? null;
        $product = Product::find($productId);
        $target = match ($mode) {
            'pack' => $item['pack'] ?? null,
            'outer' => $item['outer'] ?? null,
            default => null,
        };

        if ($item === null || $product === null || ($mode !== 'piece' && $target === null)) {
            return;
        }

        $current = self::sellingLevel($item);

        if (($current['size'] ?? 1) === ($target['size'] ?? 1) && ($current === null) === ($target === null)) {
            return;
        }

        $pieces = (int) $item['quantity'] * (int) ($current['size'] ?? 1);

        if ($target === null) {
            $this->cart[$productId] = array_merge($item, [
                'by_pack' => false,
                'by_outer' => false,
                'quantity' => min($pieces, (int) $product->stock_quantity),
                'max_stock' => $product->stock_quantity,
                'list_price' => (string) $this->piecePriceFor($product),
                'custom_price' => '',
            ]);

            return;
        }

        $size = (int) $target['size'];

        if ($product->stock_quantity < $size) {
            $this->toastError("Less than one {$target['name']} of {$product->name} in stock.");

            return;
        }

        $this->cart[$productId] = array_merge($item, [
            'by_pack' => $mode === 'pack',
            'by_outer' => $mode === 'outer',
            'quantity' => max(1, min(intdiv($pieces, $size), intdiv((int) $product->stock_quantity, $size))),
            'max_stock' => intdiv((int) $product->stock_quantity, $size),
            'list_price' => (string) $target['price'],
            'custom_price' => '',
        ]);
    }

    /**
     * A cart line as CreateSale takes it: always in pieces. A line of whole
     * packs at the pack's own price becomes pieces at that price per piece,
     * rounded up to the paisa, with the rounding given back as discount, so
     * the line totals exactly packs x pack price.
     *
     * An outer pack (a box of strips) works the same way, at its own size.
     * The pack sold goes with the line, so the sale records it.
     *
     * @return array{quantity: mixed, discount: float, custom_price: ?float, pack_name: ?string, pack_size: ?int}
     */
    public function saleLine(array $item): array
    {
        $custom = $item['custom_price'] !== '' && $item['custom_price'] !== null ? (float) $item['custom_price'] : null;
        $level = self::sellingLevel($item);
        // A wholesale customer's trade price: CreateSale sells at it unless a price was typed.
        $tier = ! empty($item['price_tier']) ? ['price_tier' => $item['price_tier']] : null;

        if ($level === null) {
            $line = ['quantity' => $item['quantity'], 'discount' => (float) $item['discount'], 'custom_price' => $custom, 'pack_name' => null, 'pack_size' => null];
            $line = $tier === null ? $line : $line + $tier + ['tier_price' => (float) $item['list_price']];

            return $this->withScheme($line, $item, is_numeric($item['quantity']) ? (float) $item['quantity'] : 0);
        }

        $packs = (int) $item['quantity'];
        $size = (int) $level['size'];
        $pieces = $packs * $size;
        $sold = ['pack_name' => $level['name'], 'pack_size' => $size];

        // No price of its own and none typed: just that many pieces at their normal prices.
        if ($custom === null && empty($level['has_price'])) {
            return $this->withScheme(['quantity' => $pieces, 'discount' => (float) $item['discount'], 'custom_price' => null] + $sold, $item, $pieces);
        }

        $packPrice = $custom ?? (float) $level['price'];
        $perPiece = ceil(round($packPrice / $size * 100, 6)) / 100;
        $rounding = round($perPiece * $pieces - $packPrice * $packs, 2);

        if ($tier !== null) {
            $tierPiece = ceil(round((float) $level['price'] / $size * 100, 6)) / 100;

            return $this->withScheme(['quantity' => $pieces, 'discount' => round((float) $item['discount'] + $rounding, 2), 'custom_price' => $custom !== null ? $perPiece : null] + $sold + $tier + ['tier_price' => $tierPiece], $item, $pieces);
        }

        return $this->withScheme(['quantity' => $pieces, 'discount' => round((float) $item['discount'] + $rounding, 2), 'custom_price' => $perPiece] + $sold, $item, $pieces);
    }


    public function scanBarcode(string $raw): void
    {
        if (! Auth::user()->canUseBarcodes()) {
            return;
        }

        $code = trim($raw);

        if ($code === '') {
            $this->search = '';

            return;
        }

        $product = Product::where('barcode', $code)->first();

        if ($product === null) {
            if (Product::where('name', 'like', "%{$code}%")->exists()) {
                return;
            }

            $this->toastError("No product found for barcode {$code}.");
            $this->search = '';
            $this->dispatch('focus-scan');

            return;
        }

        $this->search = '';
        $this->dispatch('focus-scan');

        if ($product->stock_quantity <= 0) {
            $this->toastError("{$product->name} is out of stock.");

            return;
        }

        if ($product->unit->allowsDecimals()) {
            $this->promptForQuantity($product);

            return;
        }

        $inCart = Quantity::normalize($this->cart[$product->id]['quantity'] ?? 0);

        if ($inCart + 1 > $product->stock_quantity) {
            $this->toastError("Only {$product->stock_quantity} in stock — already in cart.");

            return;
        }

        if (isset($this->cart[$product->id])) {
            $this->cart[$product->id]['quantity'] = $inCart + 1;
        } else {
            $this->cart[$product->id] = $this->cartLineFor($product);
        }

        $this->toastSuccess("{$product->name} — now {$this->cart[$product->id]['quantity']} in cart.");
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function effectivePrice(array $item): float
    {
        return $item['custom_price'] !== '' && $item['custom_price'] !== null
            ? (float) $item['custom_price']
            : (float) $item['list_price'];
    }

    /** Promotions running on the sale's day, fetched once per request. */
    protected ?array $running = null;

    protected function runningPromotions(): \Illuminate\Support\Collection
    {
        // An exchange is always today's sale (see Exchanges).
        // A date still being typed (or not a date) previews today's; validation rejects the sale itself.
        $date = BatchExpiry::today();

        if ($this->exchangeSaleId === null && $this->saleDate !== '') {
            try {
                $date = Carbon::parse($this->saleDate)->toDateString();
            } catch (\Carbon\Exceptions\InvalidFormatException) {
            }
        }

        if (($this->running['date'] ?? null) !== $date) {
            $this->running = ['date' => $date, 'promotions' => Promotions::runningOn(Auth::user()->shop_id, $date)];
        }

        return $this->running['promotions'];
    }

    /** The line as CreateSale will store it: its batches, with any promotion on them. */
    protected function pricedLine(array $item): array
    {
        $product = Product::find($item['product_id']);

        if ($product === null) {
            return [];
        }

        $line = $this->saleLine($item);
        $free = (int) ($line['scheme_free'] ?? 0);
        $allocations = ConsumeStockFifo::preview($product, Quantity::add(Quantity::normalize($line['quantity']), $free));

        return $free > 0
            ? Promotions::priceLineWithFree($product, $line['custom_price'] ?? ($line['tier_price'] ?? null), $line['discount'], $allocations, $free)
            : Promotions::priceLine($product, $line['custom_price'] ?? ($line['tier_price'] ?? null), $line['discount'], $allocations, $this->runningPromotions());
    }

    public function lineTotal(array $item): float
    {
        return array_sum(array_column($this->pricedLine($item), 'line_total'));
    }

    /** @return array{name: string, offer: string, amount: float}|null the promotion the line gets, applied by itself */
    public function promotionFor(array $item): ?array
    {
        $lines = $this->pricedLine($item);
        $id = $lines[0]['promotion_id'] ?? null;
        $promotion = $id !== null ? $this->runningPromotions()->firstWhere('id', $id) : null;

        return $promotion !== null
            ? ['name' => $promotion->name, 'offer' => $promotion->offerLabel(), 'amount' => round(array_sum(array_column($lines, 'promotion_discount')), 2)]
            : null;
    }

    /** @return array{expired: int|float, soonest: mixed}|null */
    public function expiryNote(array $item): ?array
    {
        $product = Product::find($item['product_id']);

        return $product ? BatchExpiry::noteFor($product, ConsumeStockFifo::preview($product, Quantity::normalize($this->saleLine($item)['quantity']))) : null;
    }

    public function subtotal(): float
    {
        // Service lines (SellsServices) are 0 for every shop that doesn't sell services.
        return round(array_sum(array_map(fn (array $item) => $this->lineTotal($item), $this->cart)) + $this->servicesSubtotal(), 2);
    }

    public function total(): float
    {
        $subtotal = $this->subtotal();

        return round(max(0.0, $subtotal - min((float) $this->invoiceDiscount, $subtotal)) + $this->deliveryChargeValue(), 2);
    }

    public function completeSale(): void
    {
        if (empty($this->cart) && empty($this->serviceLines)) {
            $this->toastError($this->servicesOn() ? 'Add at least one product or service.' : 'Add at least one product to the cart.');

            return;
        }

        $total = $this->total();
        $onInstallments = $this->onInstallments && $this->offersInstallments();
        $withTradeIn = $this->withTradeIn && $this->offersInstallments();
        $tradeInValue = $withTradeIn ? $this->tradeInValue() : 0.0;
        // Gift vouchers and deposits (SellsServices) count here like store credit; 0 for every other shop.
        $prepaid = $this->exchangeSaleId === null ? $this->prepaidParts() : [];
        $creditValue = round($this->creditValue() + array_sum(array_column($prepaid, 'amount')), 2);
        $exchangeValue = $this->exchangeValue();
        $toPay = round(max(0.0, $total - $tradeInValue - $exchangeValue - $creditValue), 2);
        $paidNow = $this->amountPaidNow !== '' ? (float) $this->amountPaidNow : ($onInstallments ? 0.0 : $toPay);
        $isFullyPaid = ! $onInstallments && $paidNow + $tradeInValue + $exchangeValue + $creditValue >= $total;
        // An online order's buyer becomes the customer when cash is left to collect (SellsOnline).
        $this->onlineCustomer($isFullyPaid);

        $rules = [
            'saleDate' => ['required', 'date', 'before_or_equal:today'],
            'invoiceDiscount' => ['required', 'numeric', 'min:0'],
            'amountPaidNow' => ['nullable', 'numeric', 'min:0', 'max:'.$toPay],
            'customerId' => [
                $isFullyPaid ? 'nullable' : 'required',
                'integer',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
        ];

        foreach ($this->cart as $id => $item) {
            $rules["cart.$id.quantity"] = QuantityUnit::fromValue($item['unit'] ?? 'piece')->quantityRules();
            $rules["cart.$id.discount"] = ['required', 'numeric', 'min:0'];
            $rules["cart.$id.custom_price"] = ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_LINE_PRICE];
        }

        $rules += $this->deliveryRules() + $this->serviceRules();

        if ($onInstallments) {
            $rules['installmentCount'] = ['required', 'integer', 'min:1', 'max:'.InstallmentSchedule::MAX_COUNT];
            $rules['firstDueDate'] = ['required', 'date', 'after_or_equal:saleDate'];
        }

        if ($withTradeIn) {
            $buyback = \App\Actions\Buybacks::rules();
            $rules += [
                'tradeIn.seller_name' => [$this->customerId === '' || $this->customerId === '__create__' ? 'required' : 'nullable', 'string', 'max:255'],
                'tradeIn.seller_cnic' => $buyback['seller_cnic'],
                'tradeIn.brand' => $buyback['brand'],
                'tradeIn.model' => $buyback['model'],
                'tradeIn.imei' => $buyback['imei'],
                'tradeIn.condition' => $buyback['condition'],
                'tradeIn.battery_health' => $buyback['battery_health'],
                'tradeIn.pta' => ['nullable', 'in:yes,no'],
                'tradeIn.price' => ['required', 'numeric', 'gt:0', 'max:'.$total],
                'tradeIn.selling_price' => $buyback['selling_price'],
            ];
        }

        $this->validate($rules, $onInstallments ? [
            'customerId.required' => 'Choose the customer who is paying in instalments.',
            'firstDueDate.after_or_equal' => 'The first instalment must be due on or after the sale date.',
        ] : [], ['installmentCount' => 'number of instalments', 'firstDueDate' => 'first due date',
            'tradeIn.seller_name' => 'seller name', 'tradeIn.seller_cnic' => 'CNIC', 'tradeIn.brand' => 'brand', 'tradeIn.model' => 'model', 'tradeIn.imei' => 'IMEI',
            'tradeIn.condition' => 'condition', 'tradeIn.battery_health' => 'battery health', 'tradeIn.price' => 'trade-in value', 'tradeIn.selling_price' => 'resale price'] + $this->deliveryAttributes());

        $installments = [];

        if ($onInstallments) {
            if ($paidNow >= $toPay) {
                throw ValidationException::withMessages(['amountPaidNow' => 'The down payment covers the whole sale, so there is nothing to pay in instalments.']);
            }

            $installments = InstallmentSchedule::plan($toPay - $paidNow, (int) $this->installmentCount, Carbon::parse($this->firstDueDate));
        }

        $tradeIn = null;

        if ($withTradeIn) {
            $tradeIn = collect($this->tradeIn)->except('pta')->all() + [
                'pta_approved' => match ($this->tradeIn['pta'] ?? '') { 'yes' => true, 'no' => false, default => null },
            ];
            $tradeIn['battery_health'] = ($tradeIn['battery_health'] ?? '') !== '' ? (int) $tradeIn['battery_health'] : null;
        }

        $lines = [];

        foreach ($this->cart as $productId => $item) {
            $lines[] = ['product_id' => (int) $productId] + $this->saleLine($item);
        }

        [$paymentMethod, $shopAccountId] = $this->chosenPaymentMethod();

        // Wholesale: the due date, the credit limit, stock held for orders and the booker
        // (SellsWholesale). Not for an exchange, which is settled on the spot.
        $saleAttributes = $this->exchangeSaleId === null
            ? $this->wholesaleSaleAttributes(round(max(0.0, $total - $paidNow - $tradeInValue - $exchangeValue - $creditValue), 2), $lines)
            : [];

        if ($this->exchangeSaleId !== null) {
            $this->completeExchange($lines, $paidNow, $paymentMethod, $shopAccountId, $this->creditValue());

            return;
        }

        // Online: stock pending orders hold, then the channel, delivery and its charge (SellsOnline).
        $this->assertNotHeldForPendingOrders($lines);
        $online = $this->onlineSaleArguments();

        try {
            $sale = CreateSale::handle(
                lines: $lines,
                invoiceDiscount: (float) $this->invoiceDiscount,
                customerId: $this->customerId !== '' ? (int) $this->customerId : null,
                userId: Auth::id(),
                saleDate: $this->saleDate,
                amountPaid: $paidNow,
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
                installments: $installments,
                tradeIn: $tradeIn,
                storeCredit: $this->creditValue(),
                applyPromotions: true,
                prescription: $this->prescriptionDetails(),
                attributes: $saleAttributes + $online['attributes'],
                deliveryCharge: $online['deliveryCharge'],
                delivery: $online['delivery'],
                services: $this->serviceSaleLines(),
                prepaid: $prepaid,
            );
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $key = $key === 'prescription.photo' ? 'prescriptionPhoto' : $key;
                $key = preg_match('/^services\./', $key) === 1 ? 'serviceLines' : $key;
                $errors[preg_replace('/^lines\.(\d+)$/', 'cart.$1.quantity', $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->recordParcel($sale);
        $this->afterServicesSale($sale);

        $this->toastSuccess('Sale completed.');
        // Lets the receipt open as a "sale complete" confirmation, once.
        // (An exchange goes through completeExchange() instead.)
        session()->flash('sale-completed', $sale->id);
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }
    /** Saves the return and the new sale together. */
    protected function completeExchange(array $lines, float $paidNow, ?string $paymentMethod, ?int $shopAccountId, float $creditValue): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-returns'), 403);

        $original = $this->exchangeSale() ?? abort(404);

        try {
            ['return' => $return, 'sale' => $sale] = \App\Actions\Exchanges::handle(
                $original,
                $this->exchangeLines(),
                $lines,
                customerId: ctype_digit($this->customerId) ? (int) $this->customerId : null,
                userId: Auth::id(),
                amountPaid: $paidNow,
                paymentMethod: $paymentMethod,
                shopAccountId: $shopAccountId,
                storeCredit: $creditValue,
                invoiceDiscount: (float) $this->invoiceDiscount,
                refundTo: Auth::user()->shop->cash_refunds_allowed ? $this->exchangeRefundTo : \App\Actions\SaleReturns::REFUND_CREDIT,
                refundMethod: 'cash',
                lateReason: $this->exchangeLateReason,
                byOwner: (bool) Auth::user()->is_owner,
                prescription: $this->prescriptionDetails(),
            );
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $key = preg_replace('/^lines\.(\d+)$/', 'cart.$1.quantity', $key);
                $errors[preg_match('/^(lines|returnDate|refund|creditCustomerId)/', $key) ? 'exchange' : ($key === 'prescription.photo' ? 'prescriptionPhoto' : $key)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $message = 'Exchange done: '.$return->number().' on '.$original->invoiceNumber().'.';

        if ((float) $return->refund_cash > 0) {
            $message .= ' Hand back Rs '.number_format((float) $return->refund_cash, 2).'.';
        }

        if ((float) $return->refund_credit > 0) {
            $message .= ' Rs '.number_format((float) $return->refund_credit, 2).' added to store credit.';
        }

        $this->toastSuccess('Exchange completed.');
        session()->flash('status', $message);
        session()->flash('sale-completed', $sale->id);
        $this->redirectRoute('sales.show', $sale, navigate: true);
    }
}; ?>

<div
    x-on:keydown.window.f2.prevent="$refs.scanInput.focus()"
    x-on:keydown.window.f9.prevent="$wire.completeSale()"
    @class(['pb-24 lg:pb-0' => ! empty($cart)])
>
    <x-slot name="header">
        <x-ui.page-header>{{ $exchangeSaleId ? 'Exchange' : 'New Sale' }}</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="sales" label="Sales sections" />

    <x-billing.limit-notice limit="sales_per_month" informational />

    @php($itemCount = count($cart) + count($serviceLines))

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="min-w-0 lg:col-span-2">
            @if ($exchangeSaleId && ($exchangeFrom = \App\Models\Sale::find($exchangeSaleId)))
                @php($exchangeLate = \App\Actions\SaleReturns::daysLate($exchangeFrom, auth()->user()->shop, \App\Support\BatchExpiry::today()))
                <x-ui.card :padding="false" class="mb-6" data-exchange-panel>
                    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
                        <div>
                            <h3 class="text-base font-bold tracking-tight text-ink">Exchange from {{ $exchangeFrom->invoiceNumber() }}</h3>
                            <p class="text-sm text-ink-faint">What's coming back pays for the new items below.</p>
                        </div>
                        <a href="{{ route('sales.show', $exchangeFrom) }}" wire:navigate class="text-sm font-semibold text-link hover:underline">Back to the sale</a>
                    </div>
                    @if ($exchangeLate !== null)
                        <p @class(['border-b border-line px-4 py-2.5 text-sm sm:px-5', 'bg-warning-soft text-warning' => auth()->user()->is_owner, 'bg-danger-soft text-danger' => ! auth()->user()->is_owner])>
                            This sale was {{ $exchangeLate }} days ago, past the {{ auth()->user()->shop->return_window_days }}-day limit. {{ auth()->user()->is_owner ? 'Say why below.' : 'Only the owner can exchange it now.' }}
                        </p>
                    @endif
                    @error('exchange') <p class="border-b border-line bg-danger-soft px-4 py-2.5 text-sm text-danger sm:px-5">{{ $message }}</p> @enderror
                    <ul class="divide-y divide-line">
                        @foreach (\App\Actions\SaleReturns::groups($exchangeFrom) as $key => $group)
                            <li wire:key="exchange-line-{{ $key }}" class="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_7rem_10rem] sm:items-center sm:px-5">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink">{{ $group['name'] }}</p>
                                    <p class="text-xs text-ink-faint">Sold {{ $group['unit']->amount($group['sold']) }} · Rs {{ number_format($group['unit_value'], 2) }}{{ $group['unit']->perSuffix() }} after discounts</p>
                                </div>
                                @if (\App\Support\Quantity::compare($group['returnable'], 0) > 0)
                                    <x-ui.input wire:model.live.debounce.300ms="exchangeQuantities.{{ $key }}" type="number" min="0" :max="\App\Support\Quantity::plain($group['returnable'])" :step="$group['unit']->inputStep()" placeholder="0" aria-label="How much of {{ $group['name'] }} comes back" />
                                    <div wire:key="exchange-disposition-{{ $key }}-{{ $exchangeDispositions[$key] ?? 'restock' }}">
                                        <x-ui.select wire:model="exchangeDispositions.{{ $key }}" aria-label="Condition of {{ $group['name'] }}">
                                            <option value="restock">Back to stock</option>
                                            <option value="damaged">Damaged</option>
                                        </x-ui.select>
                                    </div>
                                @else
                                    <p class="text-sm text-ink-faint sm:col-span-2 sm:text-right">All returned</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($exchangeLate !== null && auth()->user()->is_owner)
                        <div class="border-t border-line px-4 py-3 sm:px-5">
                            <x-ui.field label="Why it's taken back late" name="exchangeLateReason" for="exchangeLateReason" required>
                                <x-ui.input wire:model="exchangeLateReason" id="exchangeLateReason" maxlength="255" />
                            </x-ui.field>
                        </div>
                    @endif
                </x-ui.card>
            @endif

@if ($servicesOn && ! $exchangeSaleId)
            <x-sales.service-lines :lines="$serviceLines" :results="$serviceResults" :plans="$planResults" :staff="$staff" :package-choices="$packageChoices" :search="$serviceSearch" :billing="$billing" />
@endif
            <x-ui.card :padding="false">
                <div class="p-4 sm:p-5">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <x-ui.input
                            wire:model.live.debounce.250ms="search"
                            wire:keydown.enter.prevent="scanBarcode($event.target.value)"
                            x-ref="scanInput"
                            x-on:focus-scan.window="$refs.scanInput.focus()"
                            x-on:keydown.escape="$wire.$set('search', '')"
                            type="search"
                            placeholder="{{ $barcodeEnabled ? 'Search by name, or scan a barcode…' : 'Search by product name…' }}"
                            autofocus
                            autocomplete="off"
                            aria-label="Search products"
                            class="h-12 rounded-xl pl-12 pr-14 text-base"
                        />
                        <kbd class="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md bg-surface-sunken px-1.5 py-0.5 font-sans text-xs font-semibold text-ink-faint ring-1 ring-line sm:block">F2</kbd>
                    </div>

                    @if ($search !== '' && $picker)
                        <div class="mt-3 overflow-hidden rounded-xl border border-line">
                            <div class="flex items-center justify-between gap-3 border-b border-line bg-surface-sunken/60 px-4 py-2.5">
                                <p class="text-sm font-semibold text-ink">{{ $picker['name'] }}: choose a variant</p>
                                <button type="button" wire:click="closeVariantPicker" class="text-sm font-semibold text-link hover:underline">Back to results</button>
                            </div>
                            <div class="max-h-80 divide-y divide-line overflow-y-auto">
                                @foreach ($picker['rows'] as $row)
                                    <button
                                        type="button"
                                        wire:click="addToCart({{ $row['variant']->id }})"
                                        wire:key="picker-{{ $row['variant']->id }}"
                                        @disabled($row['variant']->stock_quantity <= 0)
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $row['variant']->variant_label }}</p>
                                            <p class="text-xs text-ink-faint">{{ $row['variant']->stock_quantity > 0 ? $row['variant']->stockLabel().' in stock' : 'Out of stock' }}</p>
                                        </div>
                                        <span class="text-sm font-bold tabular-nums text-ink">Rs {{ number_format($row['price'], 2) }}{{ $row['variant']->unit->perSuffix() }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($search !== '')
                        <div class="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line">
                            @forelse ($results as $product)
                                @if (isset($groupEntries[$product->product_group_id]))
                                    @php($entry = $groupEntries[$product->product_group_id])
                                    <button
                                        type="button"
                                        wire:click="openVariantPicker({{ $product->product_group_id }})"
                                        wire:key="result-group-{{ $product->product_group_id }}"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken"
                                    >
                                        <div class="flex items-center gap-3">
                                            <x-ui.thumbnail :src="$product->imageUrl()" :label="$entry['name']" />
                                            <div>
                                                <p class="text-sm font-semibold text-ink">{{ $entry['name'] }}</p>
                                                <p class="text-xs text-ink-faint">{{ $product->typeLabel() }} · {{ $entry['count'] }} variants · {{ $entry['unit']->amount($entry['stock']) }} in stock</p>
                                            </div>
                                        </div>
                                        <span class="text-sm font-bold tabular-nums text-ink">From Rs {{ number_format($entry['fromPrice'], 2) }}{{ $entry['unit']->perSuffix() }}</span>
                                    </button>
                                @else
                                {{-- @else rather than a loop continue: skipping past @endif leaves Livewire's block markers unbalanced and the list stops updating. --}}
                                <button
                                    type="button"
                                    wire:click="addToCart({{ $product->id }})"
                                    wire:key="result-{{ $product->id }}"
                                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-sunken"
                                >
                                    <div class="flex items-center gap-3">
                                        <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" />
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $product->name }}</p>
                                            <p class="text-xs text-ink-faint">
                                                @if ($product->generic_name)
                                                    <span data-generic-name>{{ $product->generic_name }}</span> ·
                                                    @if (mb_stripos($product->name, $search) === false && mb_stripos($product->generic_name, $search) === false)
                                                        <span class="font-semibold text-info" data-substitute>Same salt</span> ·
                                                    @endif
                                                @else
                                                    {{ $product->typeLabel() }} ·
                                                @endif
                                                {{ $product->stockLabel() }} in stock
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold tabular-nums text-ink">Rs {{ number_format($product->currentSellingPrice(), 2) }}{{ $product->unit->perSuffix() }}</span>
                                </button>
                                @endif
                            @empty
                                <p class="px-4 py-3 text-sm text-ink-faint">No in-stock products match "{{ $search }}".</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                @if (empty($cart))
                    @if ($quickPicks->isNotEmpty())
                        <div class="border-t border-line p-4 sm:p-5" data-quick-picks>
                            <h2 class="mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Frequent products</h2>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                @foreach ($quickPicks as $product)
                                    <button
                                        type="button"
                                        wire:click="addToCart({{ $product->id }})"
                                        wire:key="pick-{{ $product->id }}"
                                        data-quick-pick="{{ $product->id }}"
                                        class="group flex min-w-0 flex-col items-start gap-2 rounded-xl border border-line bg-surface p-3 text-left transition hover:border-primary/30 hover:shadow-float focus-visible:ring-2 focus-visible:ring-link"
                                    >
                                        <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" class="!h-10 !w-10 !rounded-xl" />
                                        <span class="line-clamp-2 text-sm font-semibold leading-snug text-ink">{{ $product->name }}</span>
                                        <span class="mt-auto text-sm font-bold tabular-nums text-ink">Rs {{ number_format($product->currentSellingPrice(), 2) }}{{ $product->unit->perSuffix() }}</span>
                                        <span class="text-xs text-ink-faint">{{ $product->stockLabel() }} in stock</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="border-t border-line px-6 py-8 text-center">
                        <p class="text-sm font-semibold text-ink">Cart is empty</p>
                        <p class="mt-1 text-sm text-ink-faint">{{ $quickPicks->isNotEmpty() ? 'Tap a product above, or search to start a sale.' : 'Search for a product above to start a sale.' }}</p>
                    </div>
                @else
                    <div class="flex items-center justify-between border-t border-line bg-surface-sunken/60 px-4 py-2 sm:px-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Cart · {{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total</p>
                    </div>
                    <ul class="divide-y divide-line">
                        @foreach ($cart as $productId => $item)
                            <x-sales.cart-line :product-id="$productId" :item="$item" :line-total="$this->lineTotal($item)" :expiry-note="$this->expiryNote($item)" :promotion="$this->promotionFor($item)" :tier-label="$tierLabel" :last-rate="$lastRates[$productId] ?? null" :scheme-free="$schemeFree[$productId] ?? 0" />
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @php($rxLines = $this->prescriptionLines())
            @if ($rxLines !== [])
                @php($controlled = collect($rxLines)->contains('rx', 'controlled'))
                <x-ui.card title="Prescription register" :description="collect($rxLines)->pluck('name')->implode(', ').' '.(count($rxLines) === 1 ? 'is' : 'are').' written in the register with this sale.'" class="mt-6" data-prescription-panel>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Patient name" name="prescription.patient_name" for="rxPatient" :required="$controlled" :help="$controlled ? 'Needed for a controlled drug' : null">
                            <x-ui.input wire:model="prescription.patient_name" id="rxPatient" maxlength="120" />
                        </x-ui.field>
                        <x-ui.field label="Prescriber (doctor)" name="prescription.prescriber_name" for="rxPrescriber">
                            <x-ui.input wire:model.live.debounce.400ms="prescription.prescriber_name" id="rxPrescriber" maxlength="120" />
                        </x-ui.field>
                        <x-ui.field label="PMDC / registration no." name="prescription.prescriber_reg_no" for="rxRegNo" help="Optional">
                            <x-ui.input wire:model="prescription.prescriber_reg_no" id="rxRegNo" maxlength="40" />
                        </x-ui.field>
                        <x-ui.field label="Prescription date" name="prescription.prescribed_on" for="rxDate" help="Optional">
                            <x-ui.input wire:model="prescription.prescribed_on" id="rxDate" type="date" />
                        </x-ui.field>
                        <x-ui.field label="Prescription photo" name="prescriptionPhoto" for="rxPhoto" class="sm:col-span-2" help="Optional. A photo or PDF, kept private to the shop">
                            <input type="file" wire:model="prescriptionPhoto" id="rxPhoto" accept="image/*,application/pdf" class="block w-full text-sm text-ink-faint file:mr-4 file:rounded-full file:border-0 file:bg-primary-soft file:px-4 file:py-2 file:text-sm file:font-semibold file:text-on-primary-soft hover:file:bg-primary-soft/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-link">
                        </x-ui.field>
                    </div>
                    @if (trim($prescription['prescriber_name'] ?? '') === '')
                        <div class="mt-4" data-emergency-sale>
                            <p class="rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning">No prescriber entered: this will be recorded as an <strong>emergency sale</strong>. Write the reason.</p>
                            <x-ui.field label="Emergency reason" name="prescription.emergency_reason" for="rxReason" required class="mt-3">
                                <x-ui.input wire:model="prescription.emergency_reason" id="rxReason" maxlength="255" placeholder="e.g. Inhaler refill for a regular patient" />
                            </x-ui.field>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div id="checkout" class="lg:sticky lg:top-24 lg:self-start">
            <x-ui.card :padding="false">
                <div class="p-5">
                    <div class="flex items-baseline justify-between">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">Total</p>
                        <p class="text-xs text-ink-faint">{{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                    </div>
                    <p class="mt-1 whitespace-nowrap text-4xl font-extrabold tracking-tight tabular-nums text-ink" data-register-total>Rs {{ number_format($this->total(), 2) }}</p>
                    <div class="mt-3 space-y-1 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-ink-faint">Subtotal</span>
                            <span class="font-medium tabular-nums text-ink">Rs {{ number_format($this->subtotal(), 2) }}</span>
                        </div>
                        @if ((float) $invoiceDiscount > 0)
                            <div class="flex items-center justify-between">
                                <span class="text-ink-faint">Invoice discount</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format(min((float) $invoiceDiscount, $this->subtotal()), 2) }}</span>
                            </div>
                        @endif
                        @if ($this->tradeInValue() > 0)
                            <div class="flex items-center justify-between" data-trade-in-line>
                                <span class="text-ink-faint">Trade-in (paid by phone)</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format($this->tradeInValue(), 2) }}</span>
                            </div>
                        @endif
                        @if ($this->exchangeValue() > 0)
                            <div class="flex items-center justify-between" data-exchange-line>
                                <span class="text-ink-faint">Exchange (goods back)</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format($this->exchangeValue(), 2) }}</span>
                            </div>
                        @endif
                        @if ($this->creditValue() > 0)
                            <div class="flex items-center justify-between" data-credit-line>
                                <span class="text-ink-faint">Store credit</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format($this->creditValue(), 2) }}</span>
                            </div>
                        @endif
@if ($servicesOn)
                        @foreach ($this->prepaidParts() as $part)
                            <div class="flex items-center justify-between" data-prepaid-line>
                                <span class="text-ink-faint">{{ $part['label'] }}</span>
                                <span class="font-medium tabular-nums text-ink">− Rs {{ number_format($part['amount'], 2) }}</span>
                            </div>
                        @endforeach
@endif
                        @if ($this->tradeInValue() > 0 || $this->creditValue() > 0 || $this->exchangeValue() > 0 || ($servicesOn && $this->servicePrepaidValue() > 0))
                            <div class="flex items-center justify-between font-semibold">
                                <span class="text-ink">To pay</span>
                                <span class="tabular-nums text-ink">Rs {{ number_format($this->toPay(), 2) }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div
                    class="space-y-4 border-t border-line p-5"
                    x-data="{
                        mode: $wire.onInstallments ? 'instalments' : ($wire.amountPaidNow === '' || $wire.amountPaidNow === null ? 'cash' : (parseFloat($wire.amountPaidNow) === 0 ? 'udhaar' : 'partial')),
                        pick(mode) {
                            this.mode = mode;
                            if ((mode === 'instalments') !== $wire.onInstallments) $wire.$set('onInstallments', mode === 'instalments');
                            if (mode === 'instalments') this.$nextTick(() => document.getElementById('downPayment')?.focus());
                            if (mode === 'cash') $wire.$set('amountPaidNow', '');
                            if (mode === 'udhaar') $wire.$set('amountPaidNow', '0');
                            if (mode === 'partial') {
                                if (parseFloat($wire.amountPaidNow) === 0) $wire.$set('amountPaidNow', '');
                                this.$nextTick(() => this.$refs.paid?.focus());
                            }
                        },
                    }"
                >
                    @if ($exchangeSaleId && ($leftover = $this->exchangeLeftover()) > 0)
                        <div class="rounded-xl border border-line p-3 text-sm" data-exchange-leftover>
                            <p class="font-semibold text-ink">Rs {{ number_format($leftover, 2) }} back to the customer</p>
                            <p class="text-ink-faint">The new items cost less than what's coming back.</p>
                            @if (auth()->user()->shop->cash_refunds_allowed)
                                <div class="mt-2 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 font-semibold" role="group" aria-label="Leftover as">
                                    @foreach (['cash' => 'Cash', 'credit' => 'Store credit'] as $value => $label)
                                        <label @class(['cursor-pointer rounded-full px-3 py-1 transition-colors', 'bg-surface text-ink shadow-soft ring-1 ring-line' => $exchangeRefundTo === $value, 'text-ink-faint hover:text-ink' => $exchangeRefundTo !== $value])>
                                            <input type="radio" wire:model.live="exchangeRefundTo" value="{{ $value }}" class="sr-only"> {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-1 text-ink-muted">It goes on the customer's store credit: choose the customer below.</p>
                            @endif
                        </div>
                    @endif

                    @if (($creditAvailable = $this->creditAvailable()) > 0)
                        <div class="rounded-xl border border-line p-3" data-store-credit-panel>
                            <x-ui.checkbox wire:model.live="useCredit"><span class="font-semibold text-ink">Use store credit</span> · Rs {{ number_format($creditAvailable, 2) }} available</x-ui.checkbox>
                            @if ($useCredit)
                                <x-ui.field label="From store credit (Rs)" name="storeCredit" for="creditAmount" help="Blank uses as much as covers the sale" class="mt-2">
                                    <x-ui.input wire:model.live.debounce.400ms="creditAmount" id="creditAmount" type="number" min="0" step="0.01" :max="$creditAvailable" />
                                </x-ui.field>
                            @endif
                        </div>
                    @endif

@if ($servicesOn && ! $exchangeSaleId)
                    <x-sales.prepaid-panel :vouchers-on="$vouchersOn" :vouchers="$vouchers" :deposits="$heldDeposits" />
@endif
                    @if ($offersInstallments)
                        {{-- The customer's used phone as part of the payment (a buyback recorded with the sale). --}}
                        <div data-trade-in-panel>
                            @if (! $withTradeIn)
                                <button type="button" wire:click="$set('withTradeIn', true)" class="text-sm font-semibold text-link hover:underline" data-add-trade-in>+ Add trade-in phone</button>
                            @else
                                <div class="space-y-3 rounded-xl border border-line p-3">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-semibold text-ink">Trade-in phone</p>
                                        <button type="button" wire:click="removeTradeIn" class="text-xs font-semibold text-link hover:underline">Remove</button>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <x-ui.field label="Brand" name="tradeIn.brand" for="tradeInBrand" required>
                                            <x-ui.input wire:model="tradeIn.brand" id="tradeInBrand" placeholder="Samsung" />
                                        </x-ui.field>
                                        <x-ui.field label="Model" name="tradeIn.model" for="tradeInModel">
                                            <x-ui.input wire:model="tradeIn.model" id="tradeInModel" />
                                        </x-ui.field>
                                        <x-ui.field label="IMEI" name="tradeIn.imei" for="tradeInImei" class="col-span-2">
                                            <x-ui.input wire:model="tradeIn.imei" id="tradeInImei" inputmode="numeric" autocomplete="off" />
                                        </x-ui.field>
                                        <x-ui.field label="Condition" name="tradeIn.condition" for="tradeInCondition">
                                            <x-ui.select wire:model="tradeIn.condition" id="tradeInCondition">
                                                @foreach (\App\Enums\BuybackCondition::cases() as $condition)
                                                    <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>
                                        <x-ui.field label="Battery %" name="tradeIn.battery_health" for="tradeInBattery">
                                            <x-ui.input wire:model="tradeIn.battery_health" id="tradeInBattery" type="number" min="0" max="100" />
                                        </x-ui.field>
                                        <x-ui.field label="PTA" name="tradeIn.pta" for="tradeInPta">
                                            <x-ui.select wire:model="tradeIn.pta" id="tradeInPta">
                                                <option value="">Not checked</option>
                                                <option value="yes">PTA approved</option>
                                                <option value="no">Not PTA approved</option>
                                            </x-ui.select>
                                        </x-ui.field>
                                        <div class="flex items-end gap-3 pb-2.5">
                                            <x-ui.checkbox wire:model="tradeIn.has_box" label="Box" />
                                            <x-ui.checkbox wire:model="tradeIn.has_charger" label="Charger" />
                                        </div>
                                        <x-ui.field label="Trade-in value (Rs)" name="tradeIn.price" for="tradeInPrice" required>
                                            <x-ui.input wire:model.live.debounce.400ms="tradeIn.price" id="tradeInPrice" type="number" min="0" step="0.01" />
                                        </x-ui.field>
                                        <x-ui.field label="Resale price (Rs)" name="tradeIn.selling_price" for="tradeInSellingPrice" required>
                                            <x-ui.input wire:model="tradeIn.selling_price" id="tradeInSellingPrice" type="number" min="0" step="0.01" />
                                        </x-ui.field>
                                        <x-ui.field label="Seller name" name="tradeIn.seller_name" for="tradeInSeller" help="Blank: the customer below">
                                            <x-ui.input wire:model="tradeIn.seller_name" id="tradeInSeller" />
                                        </x-ui.field>
                                        <x-ui.field label="Seller CNIC" name="tradeIn.seller_cnic" for="tradeInCnic">
                                            <x-ui.input wire:model="tradeIn.seller_cnic" id="tradeInCnic" inputmode="numeric" placeholder="35202-1234567-1" />
                                        </x-ui.field>
                                    </div>
                                    <p class="text-xs text-ink-faint">The phone goes into stock at this value and pays that much of the sale. Cancelling the sale hands it back.</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- amountPaidNow: blank = paid in full, 0 = all on udhaar. On instalments it is the down payment. --}}
                    <div>
                        <p class="mb-1.5 text-sm font-medium text-ink-muted">Payment</p>
                        <div @class(['grid gap-1 rounded-full border border-line bg-surface-sunken/60 p-1', 'grid-cols-4' => $offersInstallments, 'grid-cols-3' => ! $offersInstallments]) role="group" aria-label="Payment">
                            @foreach (['cash' => 'Cash', 'partial' => 'Partial', 'udhaar' => $takesDelivery ? 'COD' : 'Udhaar', ...($offersInstallments ? ['instalments' => 'Instalments'] : [])] as $value => $label)
                                <button
                                    type="button"
                                    x-on:click="pick('{{ $value }}')"
                                    :aria-pressed="(mode === '{{ $value }}').toString()"
                                    data-payment-mode="{{ $value }}"
                                    @class(['rounded-full py-1.5 text-sm font-semibold transition-colors', 'px-3' => ! $offersInstallments, 'px-1' => $offersInstallments])
                                    :class="mode === '{{ $value }}' ? 'bg-surface text-ink shadow-soft ring-1 ring-line' : 'text-ink-faint hover:text-ink'"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div x-show="mode === 'partial'" x-cloak>
                        <x-ui.field label="Amount Paid Now" name="amountPaidNow" for="amountPaidNow" help="Leave blank to record as paid in full">
                            <x-ui.input wire:model.live="amountPaidNow" x-ref="paid" id="amountPaidNow" type="number" min="0" step="0.01" :placeholder="number_format($this->toPay(), 2)" />
                        </x-ui.field>
                    </div>

                    @if ($offersInstallments)
                        <div x-show="mode === 'instalments'" x-cloak class="space-y-3" data-installment-panel>
                            <x-ui.field label="Down Payment" name="amountPaidNow" for="downPayment" help="Paid today. Leave blank for none">
                                <x-ui.input wire:model.live.debounce.400ms="amountPaidNow" id="downPayment" type="number" min="0" step="0.01" placeholder="0" />
                            </x-ui.field>
                            <div class="grid grid-cols-2 gap-3">
                                <x-ui.field label="Instalments" name="installmentCount" for="installmentCount" help="Monthly">
                                    <x-ui.input wire:model.live.debounce.400ms="installmentCount" id="installmentCount" type="number" min="1" max="{{ \App\Support\InstallmentSchedule::MAX_COUNT }}" step="1" />
                                </x-ui.field>
                                <x-ui.field label="First Due" name="firstDueDate" for="firstDueDate">
                                    <x-ui.input wire:model.live="firstDueDate" id="firstDueDate" type="date" />
                                </x-ui.field>
                            </div>
                            @if ($installmentPlan !== [])
                                <ol class="divide-y divide-line rounded-xl border border-line text-sm" data-installment-preview>
                                    @foreach ($installmentPlan as $row)
                                        <li class="flex items-center justify-between px-3 py-1.5">
                                            <span class="text-ink-muted">{{ $row['sequence'] }}. {{ \Carbon\Carbon::parse($row['due_date'])->format('d M Y') }}</span>
                                            <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($row['amount'], 2) }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    @endif

                    <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="amountPaidNow" />

                    <x-ui.field label="Customer" name="customerId" for="customerId" :help="$onInstallments ? 'Required — the instalments are tracked against this customer' : ($amountPaidNow !== '' && (float) $amountPaidNow < $this->toPay() ? 'Required — this sale is not being paid in full' : 'Optional — leave blank for a walk-in sale')">
                        <x-ui.select wire:model.live="customerId" id="customerId">
                            <option value="">Walk-in (no customer)</option>
                            <option value="__create__">+ New Customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $wholesale ? $customer->displayName() : $customer->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($takesDelivery)
                        <x-sales.online-delivery :channels="$channels" :couriers="$couriers" :refusals="$refusals" :with-delivery="$withDelivery" :parcel-courier="$parcelCourier" />
                    @endif

                    @if ($onInstallments || ($amountPaidNow !== '' && (float) $amountPaidNow < $this->toPay()))
                        <div class="flex items-center justify-between rounded-xl bg-warning-soft px-3 py-2 text-sm">
                            <span class="font-medium text-warning">{{ $onInstallments ? 'On Instalments' : 'Due After Sale' }}</span>
                            <span class="font-bold tabular-nums text-warning">Rs {{ number_format($this->toPay() - (float) $amountPaidNow, 2) }}</span>
                        </div>
                    @endif

                    @if ($creditStatus !== null)
                        <x-sales.wholesale-credit :status="$creditStatus" :due-date="$dueDate" />
                    @endif

                    @if ($bookers !== null)
                        <x-ui.field label="Booked by" name="bookerId" for="bookerId" help="The booker or salesman, if not you">
                            <div wire:key="booker-{{ $bookerId }}">
                                <x-ui.select wire:model="bookerId" id="bookerId">
                                    <option value="">Nobody (counter sale)</option>
                                    @foreach ($bookers as $booker)
                                        <option value="{{ $booker->id }}">{{ $booker->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>
                    @endif

                    <div class="space-y-2 text-sm">
                        <div x-data="{ editing: @js($saleDate !== now()->toDateString() || $errors->has('saleDate')) }">
                            <div x-show="! editing" class="flex items-center justify-between">
                                <span class="text-ink-faint">Sale date</span>
                                <button type="button" x-on:click="editing = true; $nextTick(() => $refs.date.focus())" class="inline-flex items-center gap-1 font-semibold text-ink hover:text-link">
                                    Today
                                    <svg class="h-4 w-4 text-ink-faint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                            <div x-show="editing" x-cloak>
                                <x-ui.field label="Sale Date" name="saleDate" for="saleDate">
                                    <x-ui.input wire:model="saleDate" x-ref="date" id="saleDate" type="date" max="{{ now()->toDateString() }}" />
                                </x-ui.field>
                            </div>
                        </div>

                        <div x-data="{ adding: @js((float) $invoiceDiscount > 0 || $errors->has('invoiceDiscount')) }">
                            <button type="button" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.discount.focus())" class="font-semibold text-link hover:underline">
                                + Add discount
                            </button>
                            <div x-show="adding" x-cloak>
                                <x-ui.field label="Invoice Discount" name="invoiceDiscount" for="invoiceDiscount">
                                    <x-ui.input wire:model.live.debounce.300ms="invoiceDiscount" x-ref="discount" id="invoiceDiscount" type="number" min="0" step="0.01" />
                                </x-ui.field>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-line p-5">
                    <x-ui.button
                        type="button"
                        wire:click="completeSale"
                        wire:loading.attr="disabled"
                        wire:target="completeSale"
                        variant="accent"
                        size="lg"
                        data-complete-sale
                        class="w-full justify-between gap-3 whitespace-nowrap px-5 py-3 text-base"
                    >
                        <span wire:loading.remove wire:target="completeSale">Complete Sale</span>
                        <span wire:loading.remove wire:target="completeSale" class="tabular-nums">Rs {{ number_format($this->total(), 2) }}</span>
                        <span wire:loading wire:target="completeSale">Completing…</span>
                    </x-ui.button>
                    <p class="mt-2 hidden text-center text-xs text-ink-faint lg:block">
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">F2</kbd> search ·
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">F9</kbd> complete ·
                        <kbd class="rounded bg-surface-sunken px-1 font-sans font-semibold ring-1 ring-line">Esc</kbd> clear search
                    </p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if (! empty($cart))
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pt-3 backdrop-blur lg:hidden" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))" data-checkout-bar>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs text-ink-faint">{{ $itemCount }} {{ \Illuminate\Support\Str::plural('line', $itemCount) }}</p>
                    <p class="text-xl font-extrabold tabular-nums text-ink">Rs {{ number_format($this->total(), 2) }}</p>
                </div>
                <x-ui.button variant="accent" x-on:click="document.getElementById('checkout').scrollIntoView({ behavior: 'smooth' })">Checkout</x-ui.button>
            </div>
        </div>
    @endif

    <x-sales.quantity-prompt :product="$promptProduct" :limit="$promptProduct?->stock_quantity" />

    <livewire:customers.quick-create />
    <livewire:shop-accounts.quick-create />
</div>
