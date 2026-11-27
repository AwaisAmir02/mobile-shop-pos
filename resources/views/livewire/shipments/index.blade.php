<?php

use App\Actions\Shipments;
use App\Couriers\CourierException;
use App\Enums\OnlineTool;
use App\Enums\ShipmentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Shipment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Every parcel booked with a courier, with its live status. Status is
 * refreshed from the courier every half hour (couriers:refresh) and on
 * demand here. A parcel the courier has returned waits for the shop to say
 * it's back in hand before the sale is cancelled and the stock restored.
 *
 * Online sellers also see parcels they typed in from the courier's own
 * portal (moved on by hand here), and on a returned parcel record what the
 * courier charges to bring it back and any goods that came back damaged (O5).
 */
new #[Layout('layouts.app')] #[Title('Shipments')] class extends Component
{
    use Toasts, WithPagination;

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(except: null)]
    public ?int $focus = null;

    public ?int $receivingId = null;

    public bool $cancelSale = true;

    public string $returnCharge = '';

    /** @var array<int, string> product id => quantity that came back damaged */
    public array $damaged = [];

    public function mount(): void
    {
        abort_unless((bool) Auth::user()->shop?->tracksParcels(), 404);
    }

    protected function recordsReturns(): bool
    {
        return (bool) Auth::user()->shop?->usesTool(OnlineTool::ParcelReturns);
    }

    /** O8: the shop sends the feedback link from its own WhatsApp. */
    public function askReview(int $id): void
    {
        abort_unless(\App\Actions\ParcelReviews::enabled(Auth::user()->shop), 403);
        $url = \App\Actions\ParcelReviews::shareUrl(Shipment::findOrFail($id));

        $url !== null ? $this->redirect($url) : $this->toastError('This parcel has no mobile number to send to.');
    }

    /** A parcel typed in from the courier's portal, moved on as the portal shows it. */
    public function mark(int $id, string $status): void
    {
        $shipment = Shipments::markManual(Shipment::findOrFail($id), ShipmentStatus::from($status));
        $this->toastSuccess($shipment->tracking_number.': '.$shipment->status->label().'.');
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function refresh(int $id): void
    {
        $shipment = Shipment::findOrFail($id);

        try {
            Shipments::refresh($shipment);
            $this->toastSuccess($shipment->tracking_number.': '.$shipment->status->label().'.');
        } catch (CourierException $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function cancel(int $id): void
    {
        Shipments::cancel(Shipment::findOrFail($id));
        $this->toastSuccess('Parcel cancelled with the courier.');
    }

    public function openReceive(int $id): void
    {
        $shipment = Shipment::findOrFail($id);
        $this->receivingId = $shipment->id;
        $this->cancelSale = Auth::user()->hasAccessTo('sales-edit-cancel');
        $this->returnCharge = $shipment->return_charge !== null ? (string) (float) $shipment->return_charge : '';
        $this->damaged = [];
        $this->dispatch('open-modal', name: 'receive-return');
    }

    public function receive(): void
    {
        $shipment = Shipment::findOrFail((int) $this->receivingId);
        abort_if($this->cancelSale && ! Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        if ($this->recordsReturns()) {
            $this->validate(['returnCharge' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'damaged.*' => ['nullable', 'numeric', 'min:0']], [], ['returnCharge' => 'return charge']);
            abort_if(array_filter($this->damaged) !== [] && ! Auth::user()->hasAccessTo('stock-ins'), 403);
        }

        Shipments::receiveReturn(
            $shipment,
            $this->cancelSale,
            Auth::id(),
            $this->recordsReturns() && $this->returnCharge !== '' ? (float) $this->returnCharge : null,
            $this->recordsReturns() ? $this->damaged : [],
        );

        $this->dispatch('close-modal', name: 'receive-return');
        $this->toastSuccess($this->cancelSale ? 'Marked as back with you. The sale is cancelled and the stock is back.' : 'Marked as back with you.');
        $this->receivingId = null;
    }

    public function with(): array
    {
        $query = Shipment::query()->with(['sale', 'connection'])->latest('id');

        $query = match ($this->show) {
            'returns' => $query->whereIn('status', [ShipmentStatus::Returning->value, ShipmentStatus::Returned->value]),
            'delivered' => $query->where('status', ShipmentStatus::Delivered->value),
            'all' => $query,
            default => $query->open(),
        };

        return [
            'shipments' => $query->paginate(20),
            'awaitingReturn' => Shipment::query()->where('status', ShipmentStatus::Returned->value)->whereNull('return_received_at')->count(),
            'codOut' => (float) Shipment::query()->open()->sum('cod_amount'),
            'openCount' => Shipment::query()->open()->count(),
            'recordsReturns' => $this->recordsReturns(),
            'asksReviews' => \App\Actions\ParcelReviews::enabled(Auth::user()->shop),
            'receivingItems' => $this->receivingId && $this->recordsReturns()
                ? \App\Models\SaleItem::query()->where('sale_id', Shipment::find($this->receivingId)?->sale_id)->whereNotNull('product_id')->get()->groupBy('product_id')
                    ->map(fn ($rows) => ['name' => $rows->first()->product_name, 'quantity' => \App\Support\Quantity::sum($rows->pluck('quantity'))])
                : collect(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Parcels booked with your couriers, and where each one is">
            Shipments
        </x-ui.page-header>
    </x-slot>

    @if (session('label_error'))
        <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ session('label_error') }}</div>
    @endif

    @if ($awaitingReturn > 0)
        <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger" data-returns-banner>
            {{ $awaitingReturn }} {{ \Illuminate\Support\Str::plural('parcel', $awaitingReturn) }} came back. When you have {{ $awaitingReturn === 1 ? 'it' : 'them' }} in hand, press <span class="font-semibold">Received back</span> to put the stock back.
        </div>
    @endif

    <x-ui.stat-row>
        <x-ui.stat label="On the Way" :value="number_format($openCount)" />
        <x-ui.stat label="Cash to Collect" :value="'Rs '.number_format($codOut, 2)" />
    </x-ui.stat-row>

    <div class="mb-4 inline-flex flex-wrap gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 text-sm font-semibold" role="group" aria-label="Show">
        @foreach (['open' => 'On the way', 'returns' => 'Returns', 'delivered' => 'Delivered', 'all' => 'All'] as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}" @class([
                'rounded-full px-3 py-1.5 transition-colors',
                'bg-surface text-ink shadow-soft ring-1 ring-line' => $show === $value,
                'text-ink-faint hover:text-ink' => $show !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($shipments->isEmpty())
        <x-ui.empty-state title="No parcels here" description="Open a sale, or an accepted WhatsApp or online-store order, and press Book courier." />
    @else
        <x-ui.table stack :headers="['Parcel', 'Order', 'Deliver to', 'COD', 'Status', '']">
            @foreach ($shipments as $shipment)
                <x-ui.table-row wire:key="shipment-{{ $shipment->id }}" data-shipment="{{ $shipment->id }}" @class(['bg-primary-soft/40' => $focus === $shipment->id, 'opacity-60' => $shipment->status === ShipmentStatus::Cancelled])>
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <span class="tabular-nums">{{ $shipment->tracking_number }}</span>
                        <span class="ui-sub">{{ $shipment->courierLabel() }} · {{ $shipment->booked_at?->format('d M Y') }}{{ $shipment->isManual() ? ' · typed in' : '' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Order">
                        @if ($shipment->sale)
                            <a href="{{ route('sales.show', $shipment->sale) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $shipment->sale->invoiceNumber() }}</a>
                        @endif
                        <span class="ui-sub">{{ $shipment->sourceLabel() }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Deliver to">
                        <span class="font-medium text-ink">{{ $shipment->consignee_name }}</span>
                        <span class="ui-sub">{{ $shipment->city_name }} · {{ $shipment->consignee_phone }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="COD" class="tabular-nums">Rs {{ number_format($shipment->cod_amount, 0) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="$shipment->status->variant()">{{ $shipment->status->label() }}</x-ui.badge>
                        @if ($shipment->return_received_at)
                            <span class="ui-sub">Back with you {{ $shipment->return_received_at->format('d M') }}</span>
                        @elseif ($shipment->last_error)
                            <span class="ui-sub text-danger" title="{{ $shipment->last_error }}">Couldn't check: {{ \Illuminate\Support\Str::limit($shipment->last_error, 60) }}</span>
                        @else
                            <span class="ui-sub">{{ $shipment->courier_status }}{{ $shipment->last_checked_at ? ' · checked '.$shipment->last_checked_at->diffForHumans() : '' }}</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <div class="flex flex-wrap justify-end gap-2">
                            @if ($shipment->awaitsReturnReceipt())
                                <x-ui.button size="sm" variant="danger" wire:click="openReceive({{ $shipment->id }})">Received back</x-ui.button>
                            @endif
                            @if ($shipment->status !== ShipmentStatus::Cancelled && ! $shipment->isManual())
                                <x-ui.button size="sm" variant="secondary" href="{{ route('shipments.label', $shipment) }}" target="_blank" data-print-label>Print label</x-ui.button>
                            @endif
                            @if (! $shipment->status->isFinal() && ! $shipment->isManual())
                                <x-ui.button size="sm" variant="secondary" wire:click="refresh({{ $shipment->id }})" wire:loading.attr="disabled" wire:target="refresh({{ $shipment->id }})">Refresh</x-ui.button>
                            @endif
                            @if (! $shipment->status->isFinal() && $shipment->isManual() && $shipment->courier_settlement_id === null)
                                <x-ui.button size="sm" variant="secondary" wire:click="mark({{ $shipment->id }}, '{{ ShipmentStatus::Delivered->value }}')" data-mark-delivered>Delivered</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="mark({{ $shipment->id }}, '{{ ShipmentStatus::Returned->value }}')" data-mark-returned>Came back</x-ui.button>
                            @endif
                            @if ($asksReviews && $shipment->status === ShipmentStatus::Delivered && $shipment->courier !== \App\Enums\CourierKey::Daraz)
                                <x-ui.button size="sm" variant="secondary" wire:click="askReview({{ $shipment->id }})" data-ask-review>{{ $shipment->review_requested_at ? 'Ask again' : 'Ask for review' }}</x-ui.button>
                            @endif
                            @if ($shipment->status === ShipmentStatus::Booked)
                                <x-ui.button size="sm" variant="secondary" wire:click="cancel({{ $shipment->id }})" confirm="Cancel parcel {{ $shipment->tracking_number }} with {{ $shipment->courierLabel() }}?">Cancel</x-ui.button>
                            @endif
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $shipments->links() }}</div>
    @endif

    <x-ui.modal name="receive-return" max-width="sm">
        <form wire:submit="receive" class="p-6">
            <h2 class="text-lg font-bold text-ink">Parcel back with you?</h2>
            <p class="mt-2 text-sm text-ink-muted">Only confirm once the parcel is in your hands. The customer didn't take it, so nothing was collected.</p>
            @if (auth()->user()->hasAccessTo('sales-edit-cancel'))
                <label class="mt-4 flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="cancelSale" class="mt-0.5 rounded border-line-strong text-primary focus:ring-link">
                    <span>Cancel the sale and put the stock back</span>
                </label>
            @endif
            @if ($recordsReturns)
                <div class="mt-4 space-y-3" data-return-extras>
                    <x-ui.field label="Return charge" name="returnCharge" for="returnCharge" help="What the courier charges to bring it back. Taken off its next payout.">
                        <x-ui.input wire:model="returnCharge" id="returnCharge" type="number" min="0" step="1" placeholder="0" />
                    </x-ui.field>
                    @if ($receivingItems->isNotEmpty() && auth()->user()->hasAccessTo('stock-ins'))
                        <div x-show="$wire.cancelSale" class="space-y-2">
                            <p class="text-sm font-medium text-ink-muted">Came back damaged? Written off once the stock is back.</p>
                            @foreach ($receivingItems as $productId => $item)
                                <x-ui.field :label="$item['name'].' (sent '.\App\Support\Quantity::plain($item['quantity']).')'" name="damaged.{{ $productId }}" for="damaged-{{ $productId }}">
                                    <x-ui.input wire:model="damaged.{{ $productId }}" id="damaged-{{ $productId }}" type="number" min="0" step="1" placeholder="0" />
                                </x-ui.field>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'receive-return' })">Not yet</x-ui.button>
                <x-ui.button type="submit">It's back</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
