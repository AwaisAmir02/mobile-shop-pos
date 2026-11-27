<?php

namespace App\Livewire\Concerns;

use App\Support\StockAvailability;
use App\Actions\Schemes;
use App\Enums\SaleStatus;
use App\Enums\WholesaleTool;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Scheme;
use App\Models\User;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use App\Support\CreditControl;
use App\Support\PriceTiers;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The register's wholesale side (livewire/sales/create). Each guard names
 * what it guards:
 *   - isWholesale() (Shop::isWholesale): trade prices and last rates (W3),
 *     the credit limit and due date (W5);
 *   - usesTool(Routes): the booker (W8); usesTool(Schemes): scheme free goods
 *     (W9); usesTool(SalesOrders): stock held for booked orders (W7).
 * For any other shop every hook hands back what it was given, so the
 * register prices and sells exactly as it always has.
 *
 * The register calls in through wholesaleCartLine() for each new line,
 * withScheme() when it builds a sale line, wholesaleCustomerChanged() when
 * the customer changes, wholesaleSaleAttributes() before completing a sale,
 * and wholesaleViewData() in with().
 *
 * Lookups are kept for one request only (remember(): a protected property,
 * which Livewire does not carry between requests), so nothing shown can go
 * stale; the credit check is never kept, since a sale changes it.
 */
trait SellsWholesale
{
    // When a credit sale falls due (blank = the customer's payment terms), and
    // the owner's approval to go over a customer's credit limit (W5). The
    // password is never kept after a try.
    public string $dueDate = '';
    public bool $overrideCredit = false;
    public string $overrideReason = '';
    public string $ownerPassword = '';

    // Who booked or sold it on the route, apart from who rings it up (W8).
    public string $bookerId = '';

    /** @var array<string, mixed> this request's lookups */
    protected array $wholesaleMemo = [];

    protected function remember(string $key, \Closure $load): mixed
    {
        if (! array_key_exists($key, $this->wholesaleMemo)) {
            $this->wholesaleMemo[$key] = $load();
        }

        return $this->wholesaleMemo[$key];
    }

    protected function isWholesale(): bool
    {
        return $this->remember('wholesale', fn () => (bool) Auth::user()->shop?->isWholesale());
    }

    protected function usesTool(WholesaleTool $tool): bool
    {
        return $this->remember('tool:'.$tool->value, fn () => (bool) Auth::user()->shop?->usesTool($tool));
    }

    protected function chosenCustomer(): ?Customer
    {
        if (! ctype_digit($this->customerId)) {
            return null;
        }

        return $this->remember('customer:'.$this->customerId, fn () => Customer::find((int) $this->customerId));
    }

    // ── Prices (W3) ───────────────────────────────────────────────────────

    /** One piece at the chosen customer's price level. */
    protected function piecePriceFor(Product $product): float
    {
        return $this->isWholesale()
            ? PriceTiers::priceFor($product, $this->chosenCustomer())['price']
            : $product->currentSellingPrice();
    }

    /** A new cart line priced for the chosen customer, with any running scheme (W9). */
    protected function wholesaleCartLine(array $line, Product $product): array
    {
        if ($this->usesTool(WholesaleTool::Schemes) && ($scheme = $this->runningScheme($product->id)) !== null) {
            $line['scheme'] = ['id' => $scheme->id, 'name' => $scheme->name, 'offer' => $scheme->offerLabel()];
        }

        return $this->isWholesale() ? $this->withTradePrices($line, $product) : $line;
    }

    /** The customer's price on the line's piece, pack and outer pack. */
    protected function withTradePrices(array $line, Product $product): array
    {
        $customer = $this->chosenCustomer();
        $piece = PriceTiers::priceFor($product, $customer);

        $line['normal_price'] = $product->currentSellingPrice();
        $line['price_tier'] = $piece['tier']?->value;
        $line['list_price'] = (string) $piece['price'];

        foreach (['pack' => PriceTiers::PACK, 'outer' => PriceTiers::OUTER] as $key => $level) {
            if ($line[$key] !== null) {
                $priced = PriceTiers::priceFor($product, $customer, $level);
                $line[$key]['price'] = $priced['price'];
                $line[$key]['has_price'] = $priced['tier'] !== null || $line[$key]['has_price'];
            }
        }

        return $line;
    }

    /**
     * The customer changed: every line takes that customer's price, at the
     * level (piece, pack, outer) it is being sold in. A price the cashier
     * typed stays as typed.
     */
    protected function repriceCart(): void
    {
        foreach ($this->cart as $productId => $item) {
            $product = Product::find($productId);

            if ($product === null) {
                continue;
            }

            $fresh = $this->cartLineFor($product, $item['quantity']);
            $mode = match (true) {
                ! empty($item['by_outer']) && $fresh['outer'] !== null => 'outer',
                ! empty($item['by_pack']) && $fresh['pack'] !== null => 'pack',
                default => 'piece',
            };

            $this->cart[$productId] = array_merge($item, [
                'pack' => $fresh['pack'],
                'outer' => $fresh['outer'],
                'normal_price' => $fresh['normal_price'],
                'price_tier' => $fresh['price_tier'],
                'list_price' => match ($mode) {
                    'outer' => (string) $fresh['outer']['price'],
                    'pack' => (string) $fresh['pack']['price'],
                    default => $fresh['list_price'],
                },
            ]);
        }
    }

    /**
     * What the chosen customer last paid for each product in the cart, from
     * their latest sale of it (any branch), so staff can check the price.
     *
     * @return array<int, array{price: float, pack: ?string, pack_size: ?int, date: \Carbon\Carbon}>
     */
    protected function lastRates(): array
    {
        $customer = $this->chosenCustomer();

        if (! $this->isWholesale() || $customer === null || $this->cart === []) {
            return [];
        }

        $productIds = array_keys($this->cart);
        sort($productIds);

        return $this->remember('last-rates:'.$customer->id.':'.implode(',', $productIds), fn () => BranchContext::current()->shopWide(function () use ($customer, $productIds) {
            $ids = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.customer_id', $customer->id)
                ->where('sales.status', '!=', SaleStatus::Cancelled->value)
                ->whereIn('sale_items.product_id', $productIds)
                ->groupBy('sale_items.product_id')
                ->selectRaw('MAX(sale_items.id) as id')
                ->pluck('id');

            return SaleItem::query()->whereIn('id', $ids)->with('sale:id,created_at')->get()
                ->mapWithKeys(fn (SaleItem $item) => [$item->product_id => [
                    'price' => (float) $item->unit_price,
                    'pack' => $item->pack_name,
                    'pack_size' => $item->pack_size,
                    'date' => $item->sale->created_at,
                ]])
                ->all();
        }));
    }

    /** Puts the customer's last rate on a line, at the level it is being sold in. */
    public function useLastRate(int $productId): void
    {
        $rate = $this->lastRates()[$productId] ?? null;

        if ($rate === null || ! isset($this->cart[$productId])) {
            return;
        }

        $level = self::sellingLevel($this->cart[$productId]);
        $this->cart[$productId]['custom_price'] = (string) round($rate['price'] * (int) ($level['size'] ?? 1), 2);
    }

    // ── Schemes (W9) ──────────────────────────────────────────────────────

    protected function runningScheme(int $productId): ?Scheme
    {
        return $this->remember('running-scheme:'.$productId, fn () => Schemes::runningFor($productId, BatchExpiry::today()));
    }

    /** Free scheme pieces a cart line earns, for the pieces it sells. */
    protected function schemeFree(array $item, int|float $pieces): int
    {
        if (empty($item['scheme']['id'])) {
            return 0;
        }

        $scheme = $this->remember('scheme:'.$item['scheme']['id'], fn () => Scheme::find($item['scheme']['id']));

        return $scheme?->freeFor($pieces) ?? 0;
    }

    /** Adds a line's free scheme pieces (CreateSale prices them at nothing). */
    protected function withScheme(array $line, array $item, int|float $pieces): array
    {
        $free = $this->schemeFree($item, $pieces);

        return $free > 0 ? $line + ['scheme_id' => $item['scheme']['id'], 'scheme_free' => $free] : $line;
    }

    // ── Customer, booker and credit (W3, W5, W8) ──────────────────────────

    /** The customer changed: their route's booker, their prices, and a fresh credit approval. */
    protected function wholesaleCustomerChanged(): void
    {
        if (! $this->isWholesale()) {
            return;
        }

        if ($this->usesTool(WholesaleTool::Routes) && ($booker = $this->chosenCustomer()?->deliveryRoute?->booker_id)) {
            $this->bookerId = (string) $booker;
        }

        $this->repriceCart();
        $this->dueDate = '';
        $this->overrideCredit = false;
        $this->overrideReason = '';
        $this->ownerPassword = '';
    }

    /** What this sale leaves unpaid, after everything paid now. */
    protected function unpaidAfterSale(): float
    {
        // toPay() prices the whole cart, so once.
        $toPay = $this->toPay();
        $paidNow = $this->amountPaidNow !== '' && is_numeric($this->amountPaidNow) ? (float) $this->amountPaidNow : $toPay;

        return round(max(0.0, $toPay - $paidNow), 2);
    }

    /** The chosen customer's credit position with this sale, for the panel; null without a customer. */
    protected function creditStatus(): ?array
    {
        $customer = $this->chosenCustomer();

        if (! $this->isWholesale() || $customer === null) {
            return null;
        }

        $unpaid = $this->unpaidAfterSale();

        return [
            'customer' => $customer,
            'unpaid' => $unpaid,
            'check' => CreditControl::check($customer, $unpaid),
            'terms' => $customer->payment_terms_days,
            'defaultDue' => $this->termsDueDate($customer),
            'isOwner' => (bool) Auth::user()->is_owner,
        ];
    }

    protected function termsDueDate(Customer $customer): ?string
    {
        if (! $customer->payment_terms_days) {
            return null;
        }

        try {
            return Carbon::parse($this->saleDate ?: now()->toDateString())->addDays($customer->payment_terms_days)->toDateString();
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }
    }

    /**
     * What completing a sale adds for a wholesale business: the due date, any
     * credit-limit approval and the booker. Refuses a sale over the limit until
     * the owner approves it, and stock held for booked orders. Not for an
     * exchange, which is settled on the spot.
     *
     * @param  list<array>  $lines  the lines going to CreateSale
     * @return array<string, mixed> Sale attributes
     */
    protected function wholesaleSaleAttributes(float $unpaid, array $lines): array
    {
        if (! $this->isWholesale()) {
            return [];
        }

        $attributes = $this->creditAttributes($unpaid);
        $this->assertNotHeldForOrders($lines);

        if ($this->usesTool(WholesaleTool::Routes) && ctype_digit($this->bookerId) && User::where('shop_id', Auth::user()->shop_id)->whereKey((int) $this->bookerId)->exists()) {
            $attributes['booker_id'] = (int) $this->bookerId;
        }

        return $attributes;
    }

    /** @return array<string, mixed> the due date and any approval, for a sale that leaves money unpaid */
    protected function creditAttributes(float $unpaid): array
    {
        $customer = $this->chosenCustomer();

        if ($customer === null || $unpaid <= 0) {
            return [];
        }

        $this->validate(['dueDate' => ['nullable', 'date', 'after_or_equal:saleDate']], ['dueDate.after_or_equal' => 'The due date can\'t be before the sale date.'], ['dueDate' => 'due date']);

        $attributes = ['due_date' => $this->dueDate !== '' ? Carbon::parse($this->dueDate)->toDateString() : $this->termsDueDate($customer)];
        $check = CreditControl::check($customer, $unpaid);

        if ($check === null || ! $check['over']) {
            return $attributes;
        }

        $password = $this->ownerPassword;
        $this->ownerPassword = '';

        if (! $this->overrideCredit) {
            throw ValidationException::withMessages(['credit' => 'This takes '.$customer->name.' to Rs '.number_format($check['after'], 2).', over their credit limit of Rs '.number_format($check['limit'], 2).'. Take more payment now, or have the owner approve it.']);
        }

        $this->validate(['overrideReason' => ['required', 'string', 'max:255']], ['overrideReason.required' => 'Say why the limit is being passed.'], ['overrideReason' => 'reason']);
        $owner = CreditControl::approvingOwner(Auth::user(), $password);

        if ($owner === null) {
            throw ValidationException::withMessages(['ownerPassword' => 'That is not the owner\'s password.']);
        }

        return $attributes + ['credit_override_by' => $owner->id, 'credit_override_reason' => $this->overrideReason];
    }

    /** Refuses selling stock that open orders hold (W7). */
    protected function assertNotHeldForOrders(array $lines): void
    {
        if (! $this->usesTool(WholesaleTool::SalesOrders)) {
            return;
        }

        // A scheme's free pieces leave the shelf too.
        StockAvailability::assertFree(
            array_map(fn (array $line) => ['product_id' => $line['product_id'], 'quantity' => Quantity::add(Quantity::normalize($line['quantity']), (int) ($line['scheme_free'] ?? 0))], $lines),
            fn (int $productId) => "cart.{$productId}.quantity",
        );
    }

    /** @return array<string, mixed> the register view's wholesale data (empty for every other shop) */
    protected function wholesaleViewData(): array
    {
        $wholesale = $this->isWholesale();

        return [
            'wholesale' => $wholesale,
            'tierLabel' => $wholesale ? PriceTiers::label(Auth::user()->shop) : null,
            'lastRates' => $wholesale ? $this->lastRates() : [],
            'creditStatus' => $wholesale ? $this->creditStatus() : null,
            'bookers' => $this->usesTool(WholesaleTool::Routes) ? User::where('shop_id', Auth::user()->shop_id)->orderBy('name')->get() : null,
            'schemeFree' => $wholesale ? collect($this->cart)->map(fn (array $item) => (int) ($this->saleLine($item)['scheme_free'] ?? 0))->all() : [],
        ];
    }
}
