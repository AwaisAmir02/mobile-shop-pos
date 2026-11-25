<?php

use App\Actions\SalesOrders;
use App\Livewire\Concerns\Toasts;
use App\Models\DeliveryRoute;
use App\Models\LoadSheet;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/** Load sheets (W7): put booked orders on a van for a day's run. */
new #[Layout('layouts.app')] #[Title('Load Sheets')] class extends Component
{
    use Toasts;

    public string $loadDate = '';
    public string $vehicle = '';
    public string $driver = '';
    public string $salesmanId = '';
    public string $routeId = '';
    public string $note = '';

    /** @var list<string> */
    public array $orderIds = [];

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->usesTool('orders'), 404);
        $this->loadDate = BatchExpiry::today();
    }

    public function updatedRouteId(): void
    {
        $this->orderIds = SalesOrder::query()->where('status', SalesOrder::BOOKED)->whereNull('load_sheet_id')
            ->when($this->routeId !== '', fn ($q) => $q->where('route_id', (int) $this->routeId))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function create(): void
    {
        $this->validate([
            'loadDate' => ['required', 'date'],
            'vehicle' => ['nullable', 'string', 'max:60'],
            'driver' => ['nullable', 'string', 'max:80'],
            'salesmanId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('shop_id', Auth::user()->shop_id)],
            'routeId' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['loadDate' => 'date', 'salesmanId' => 'salesman']);

        $sheet = SalesOrders::createLoadSheet(array_map('intval', $this->orderIds), $this->loadDate, $this->vehicle, $this->driver, $this->salesmanId !== '' ? (int) $this->salesmanId : null, $this->routeId !== '' ? (int) $this->routeId : null, $this->note, Auth::id());

        $this->redirectRoute('load-sheets.show', $sheet, navigate: true);
    }

    public function with(): array
    {
        return [
            'sheets' => LoadSheet::query()->with(['route', 'salesman'])->withCount('orders')->latest('load_date')->latest('id')->limit(100)->get(),
            'waiting' => SalesOrder::query()->where('status', SalesOrder::BOOKED)->whereNull('load_sheet_id')
                ->when($this->routeId !== '', fn ($q) => $q->where('route_id', (int) $this->routeId))
                ->with(['customer', 'route'])->orderBy('delivery_date')->get(),
            'routes' => DeliveryRoute::query()->orderBy('name')->get(),
            'users' => User::query()->where('shop_id', Auth::user()->shop_id)->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales-orders.index') }}" back-label="Orders" subtitle="What goes out on each van, and what came back">Load Sheets</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Load a van" class="lg:col-span-1" data-new-sheet>
            <div class="space-y-4">
                <x-ui.field label="Route" name="routeId" for="sheetRoute" help="Picks its booked orders">
                    <x-ui.select wire:model.live="routeId" id="sheetRoute">
                        <option value="">Any route</option>
                        @foreach ($routes as $route)
                            <option value="{{ $route->id }}">{{ $route->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.field label="Date" name="loadDate" for="loadDate">
                        <x-ui.input wire:model="loadDate" id="loadDate" type="date" />
                    </x-ui.field>
                    <x-ui.field label="Vehicle" name="vehicle" for="vehicle">
                        <x-ui.input wire:model="vehicle" id="vehicle" maxlength="60" placeholder="e.g. LES-4512" />
                    </x-ui.field>
                </div>
                <x-ui.field label="Driver" name="driver" for="driver">
                    <x-ui.input wire:model="driver" id="driver" maxlength="80" />
                </x-ui.field>
                <x-ui.field label="Salesman" name="salesmanId" for="salesman" help="Who delivers and collects">
                    <x-ui.select wire:model="salesmanId" id="salesman">
                        <option value="">Nobody</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div>
                    <p class="text-sm font-medium text-ink">Orders to load</p>
                    @error('orderIds')<p class="text-xs text-danger">{{ $message }}</p>@enderror
                    @if ($waiting->isEmpty())
                        <p class="mt-1 text-sm text-ink-faint">No booked orders waiting.</p>
                    @else
                        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto">
                            @foreach ($waiting as $order)
                                <x-ui.checkbox wire:model="orderIds" :value="(string) $order->id">{{ $order->number() }} · {{ $order->customer?->displayName() }} · Rs {{ number_format($order->estimated_total, 0) }}</x-ui.checkbox>
                            @endforeach
                        </div>
                    @endif
                </div>
                <x-ui.button wire:click="create" class="w-full justify-center" data-create-sheet>Create Load Sheet</x-ui.button>
            </div>
        </x-ui.card>

        <div class="lg:col-span-2">
            @if ($sheets->isEmpty())
                <x-ui.empty-state title="No load sheets yet" description="Load booked orders onto a van to print what to load and the stops in route order." />
            @else
                <x-ui.table stack :headers="['Sheet', 'Date', 'Route', 'Orders', 'Status']">
                    @foreach ($sheets as $sheet)
                        <x-ui.table-row wire:key="sheet-{{ $sheet->id }}">
                            <x-ui.table-cell primary>
                                <a href="{{ route('load-sheets.show', $sheet) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $sheet->number() }}</a>
                                <span class="block text-xs text-ink-faint">{{ collect([$sheet->vehicle, $sheet->salesman?->name])->filter()->implode(' · ') }}</span>
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Date">{{ $sheet->load_date->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell label="Route">{{ $sheet->route?->name ?? '—' }}</x-ui.table-cell>
                            <x-ui.table-cell label="Orders">{{ $sheet->orders_count }}</x-ui.table-cell>
                            <x-ui.table-cell label="Status">{{ $sheet->isOpen() ? 'Out' : 'Settled' }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </div>
</div>
