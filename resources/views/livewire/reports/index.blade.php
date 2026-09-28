<?php

use App\Enums\PaymentStatus;
use App\Enums\ShopScreen;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\BillCategory;
use App\Models\BillProvider;
use App\Models\MainCategory;
use App\Models\Network;
use App\Models\Product;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\ReportQueries\BalanceLoadReport;
use App\ReportQueries\BillReport;
use App\ReportQueries\NadraVerificationReport;
use App\ReportQueries\ProductReport;
use App\ReportQueries\RepairReport;
use App\ReportQueries\SalesReport;
use App\ReportQueries\ScreenReport;
use App\ReportQueries\SimSaleReport;
use App\ReportQueries\StockInReport;
use App\ReportQueries\UdhaarReport;
use App\ReportQueries\WalletLoadReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A screen-selectable report builder: pick which of the 10 report-capable
 * screens to look at, then filter/summarize/export exactly like that
 * screen's own History page — because it routes through the exact same
 * ReportQueries\* class that screen's own History component uses (see
 * App\ReportQueries\ScreenReport), there is nowhere for the two to drift
 * apart. This screen's own table/export always render via tableHeaders()/
 * tableRows() rather than a bespoke per-screen table partial, so it shows
 * the same plain, un-badged data the PDF/Excel export already contains —
 * deliberately simpler than each screen's own richly-linked History table.
 */
new #[Layout('layouts.app')] #[Title('Reports')] class extends Component
{
    use GuardsExportSize, Toasts;

    /** Fixed display order — not alphabetical, roughly grouped by how the sidebar itself orders these. */
    protected const SCREEN_ORDER = [
        ShopScreen::Products,
        ShopScreen::Sales,
        ShopScreen::StockIns,
        ShopScreen::WalletLoads,
        ShopScreen::NadraVerifications,
        ShopScreen::SimSales,
        ShopScreen::Udhaar,
        ShopScreen::BalanceLoads,
        ShopScreen::Bills,
        ShopScreen::Repairs,
    ];

    public string $screen = '';

    // Shared across most screens.
    public string $from = '';
    public string $to = '';
    public string $paymentStatusFilter = '';

    // Products
    public string $search = '';
    public string $typeFilter = '';

    // Stock In
    public string $productFilter = '';

    // Wallet Loads
    public string $tab = 'cash_in';
    public string $provider = '';
    public string $shopAccountId = '';

    // SIM Sale
    public string $network = '';

    // Bills
    public string $billCategoryId = '';
    public string $billProviderId = '';

    // Repairs
    public string $category = '';

    public function mount(): void
    {
        $this->screen = $this->accessibleScreens()[0]?->value ?? '';
    }

    /** @return array<int, ShopScreen> */
    protected function accessibleScreens(): array
    {
        return array_values(array_filter(
            self::SCREEN_ORDER,
            fn (ShopScreen $screen) => Auth::user()->hasAccessTo($screen)
        ));
    }

    /**
     * A tampered dropdown value (a real screen this user just doesn't have
     * access to) silently reverts to the first accessible screen rather
     * than erroring — this is a display filter, not a destructive action.
     * The actual security boundary against ever reading or exporting an
     * inaccessible screen's data is reportIfAccessible(), which with(),
     * exportPdf(), and exportExcel() all go through independently of
     * whatever $screen holds.
     */
    public function updatedScreen(): void
    {
        if (! collect($this->accessibleScreens())->contains(fn ($s) => $s->value === $this->screen)) {
            $this->screen = $this->accessibleScreens()[0]?->value ?? '';
        }

        $this->reset([
            'from', 'to', 'paymentStatusFilter', 'search', 'typeFilter', 'productFilter',
            'provider', 'shopAccountId', 'network', 'billCategoryId', 'billProviderId', 'category',
        ]);
        $this->tab = 'cash_in';
    }

    public function updatingBillCategoryId(): void
    {
        $this->billProviderId = '';
    }

    protected function report(): ScreenReport
    {
        return match ($this->screen) {
            ShopScreen::Products->value => new ProductReport($this->search, $this->typeFilter),
            ShopScreen::Sales->value => new SalesReport($this->from, $this->to),
            ShopScreen::StockIns->value => new StockInReport($this->from, $this->to, $this->productFilter, $this->paymentStatusFilter),
            ShopScreen::WalletLoads->value => new WalletLoadReport($this->tab, $this->from, $this->to, $this->provider, $this->shopAccountId, $this->paymentStatusFilter),
            ShopScreen::NadraVerifications->value => new NadraVerificationReport($this->from, $this->to, $this->paymentStatusFilter),
            ShopScreen::SimSales->value => new SimSaleReport($this->from, $this->to, $this->network, $this->paymentStatusFilter),
            ShopScreen::Udhaar->value => new UdhaarReport,
            ShopScreen::BalanceLoads->value => new BalanceLoadReport($this->from, $this->to, $this->paymentStatusFilter),
            ShopScreen::Bills->value => new BillReport($this->from, $this->to, $this->billCategoryId, $this->billProviderId, $this->paymentStatusFilter),
            ShopScreen::Repairs->value => new RepairReport($this->from, $this->to, $this->category, $this->paymentStatusFilter),
            default => null,
        };
    }

    /**
     * The single access checkpoint every read of report data goes through —
     * with(), exportPdf(), and exportExcel() all call this rather than
     * report() directly, so a tampered $screen value (one this user has no
     * access to, even if it's a real screen) can never produce data or a
     * file, regardless of which entry point tries to read it.
     */
    protected function reportIfAccessible(): ?ScreenReport
    {
        $accessible = collect($this->accessibleScreens())->contains(fn ($s) => $s->value === $this->screen);

        return $accessible ? $this->report() : null;
    }

    public function with(): array
    {
        $accessible = $this->accessibleScreens();
        $report = $this->reportIfAccessible();

        return [
            'accessibleScreens' => $accessible,
            'summaryCards' => $report?->summaryCards() ?? [],
            'tableHeaders' => $report?->tableHeaders() ?? [],
            'tableRows' => $report?->tableRows(forExcel: false) ?? [],
            'paymentStatuses' => PaymentStatus::cases(),
            'mainCategories' => MainCategory::query()->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->get(),
            'allProviders' => WalletLoad::query()
                ->where('direction', $this->tab)
                ->whereNotNull('provider')
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider'),
            'allShopAccounts' => ShopAccount::query()->orderBy('name')->get(),
            'allNetworks' => Network::query()->orderBy('name')->pluck('name'),
            'allBillCategories' => BillCategory::query()->orderBy('name')->get(),
            'allBillProviders' => BillProvider::query()
                ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId))
                ->orderBy('name')
                ->get(),
        ];
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->reportIfAccessible();
        abort_if($report === null, 403);

        return $this->guardExportSize(fn () => $exportService->toPdf(
            ShopScreen::from($this->screen)->label(),
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: false),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->reportIfAccessible();
        abort_if($report === null, 403);

        return $this->guardExportSize(fn () => $exportService->toExcel(
            ShopScreen::from($this->screen)->label(),
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: true),
            $report->columnTypes(),
        ));
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Reports</h1>
    </x-slot>

    @if (empty($accessibleScreens))
        <x-ui.empty-state
            title="No reports available"
            description="You don't have access to any of the screens this report builder covers."
        />
    @else
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.field label="Screen" name="screen" for="screen" class="sm:max-w-xs">
                <x-ui.select wire:model.live="screen" id="screen">
                    @foreach ($accessibleScreens as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.export-dropdown />
        </div>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:flex-wrap">
            @if ($screen === ShopScreen::Products->value)
                <x-ui.field label="Search" name="search" for="search" class="sm:max-w-xs">
                    <x-ui.input wire:model.live.debounce.400ms="search" type="search" id="search" placeholder="Search products…" />
                </x-ui.field>

                <x-ui.field label="Main Category" name="typeFilter" for="typeFilter" class="sm:max-w-[10rem]">
                    <x-ui.select wire:model.live="typeFilter" id="typeFilter">
                        <option value="">All categories</option>
                        @foreach ($mainCategories as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @elseif ($screen === ShopScreen::Udhaar->value)
                {{-- No filters — matches the Udhaar main list, which has none either. --}}
            @else
                <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="from" id="from" type="date" />
                </x-ui.field>

                <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="to" id="to" type="date" />
                </x-ui.field>

                @if ($screen === ShopScreen::StockIns->value)
                    <x-ui.field label="Product" name="productFilter" for="productFilter" class="sm:max-w-[12rem]">
                        <x-ui.select wire:model.live="productFilter" id="productFilter">
                            <option value="">All Products</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                @if ($screen === ShopScreen::WalletLoads->value)
                    <x-ui.field label="Direction" name="tab" for="tab" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="tab" id="tab">
                            <option value="cash_in">Cash In</option>
                            <option value="cash_out">Cash Out</option>
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Provider" name="provider" for="provider" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="provider" id="provider">
                            <option value="">All Providers</option>
                            @foreach ($allProviders as $name)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Shop Account" name="shopAccountId" for="shopAccountId" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="shopAccountId" id="shopAccountId">
                            <option value="">All Accounts</option>
                            @foreach ($allShopAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                @if ($screen === ShopScreen::SimSales->value)
                    <x-ui.field label="Network" name="network" for="network" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="network" id="network">
                            <option value="">All Networks</option>
                            @foreach ($allNetworks as $name)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                @if ($screen === ShopScreen::Bills->value)
                    <x-ui.field label="Category" name="billCategoryId" for="billCategoryId" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="billCategoryId" id="billCategoryId">
                            <option value="">All Categories</option>
                            @foreach ($allBillCategories as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Provider" name="billProviderId" for="billProviderId" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="billProviderId" id="billProviderId">
                            <option value="">All Providers</option>
                            @foreach ($allBillProviders as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                @if ($screen === ShopScreen::Repairs->value)
                    <x-ui.field label="Category" name="category" for="category" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="category" id="category">
                            <option value="">All Categories</option>
                            @foreach ($mainCategories as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                @unless (in_array($screen, [ShopScreen::Sales->value], true))
                    <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="sm:max-w-[10rem]">
                        <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                            <option value="">All Statuses</option>
                            @foreach ($paymentStatuses as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endunless
            @endif
        </div>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($summaryCards as $card)
                <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
            @endforeach
        </div>

        @if (empty($tableRows))
            <x-ui.empty-state
                title="No records"
                description="Nothing matches the current filters."
            />
        @else
            <x-ui.table :headers="$tableHeaders">
                @foreach ($tableRows as $i => $row)
                    <x-ui.table-row wire:key="report-row-{{ $i }}">
                        @foreach ($row as $cell)
                            <x-ui.table-cell>{{ $cell }}</x-ui.table-cell>
                        @endforeach
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @endif
</div>
