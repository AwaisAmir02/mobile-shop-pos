<?php

use App\Actions\Shipments;
use App\Couriers\CourierException;
use App\Livewire\Concerns\Toasts;
use App\Models\CourierConnection;
use App\Models\Sale;
use App\Models\Shipment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Book a courier for one sale. Delivery details start from the WhatsApp or
 * online-store order the sale came from (else its customer); the amount to
 * collect starts at what's still owed on the sale.
 */
new #[Layout('layouts.app')] #[Title('Book a Courier')] class extends Component
{
    use Toasts;

    #[Locked]
    public int $saleId;

    #[Locked]
    public ?int $whatsappOrderId = null;

    #[Locked]
    public ?int $storeOrderId = null;

    public string $connectionId = '';
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $city = '';
    public string $cod = '0';
    public string $weight = '500';
    public string $pieces = '1';
    public string $description = '';
    public string $instructions = '';

    protected string $cityHint = '';

    public function mount(Sale $sale): void
    {
        abort_unless(Auth::user()->shop?->couriers_enabled, 404);
        abort_if($sale->isCancelled(), 404);

        $this->saleId = $sale->id;
        $prefill = Shipments::prefill($sale);

        $this->name = $prefill['name'];
        $this->phone = $prefill['phone'];
        $this->address = $prefill['address'];
        $this->cod = (string) $prefill['cod'];
        $this->description = $prefill['description'];
        $this->whatsappOrderId = $prefill['whatsapp_order_id'];
        $this->storeOrderId = $prefill['store_order_id'];
        $this->cityHint = $prefill['city'];

        $first = CourierConnection::query()->where('status', 'connected')->orderBy('id')->first();

        if ($first) {
            $this->connectionId = (string) $first->id;
            $this->weight = (string) ($first->setting('default_weight_grams', 500));
            $this->guessCity();
        }
    }

    protected function connection(): ?CourierConnection
    {
        return $this->connectionId === '' ? null : CourierConnection::query()->where('status', 'connected')->find((int) $this->connectionId);
    }

    /** @return array<string, string> */
    protected function cities(): array
    {
        $connection = $this->connection();

        if (! $connection) {
            return [];
        }

        try {
            return $connection->courier->provider()->cities($connection);
        } catch (CourierException $e) {
            $this->addError('city', $e->getMessage());

            return [];
        }
    }

    /** Picks the courier's city whose name appears in the delivery area or address. */
    protected function guessCity(): void
    {
        $haystack = mb_strtolower($this->cityHint.' '.$this->address);

        foreach ($this->cities() as $value => $name) {
            if (mb_strlen($name) >= 4 && preg_match('/\b'.preg_quote(mb_strtolower($name), '/').'\b/u', $haystack)) {
                $this->city = (string) $value;

                return;
            }
        }
    }

    public function updatedConnectionId(): void
    {
        $this->city = '';
        $this->guessCity();
    }

    public function book(): void
    {
        $sale = Sale::findOrFail($this->saleId);
        $connection = $this->connection();

        $this->validate([
            'connectionId' => ['required'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', fn ($a, $v, $fail) => preg_match('/^0\d{10}$/', Shipments::localPhone($v)) ? null : $fail('Enter a mobile number like 0300 1234567.')],
            'address' => ['required', 'string', 'min:10', 'max:300'],
            'city' => ['required', fn ($a, $v, $fail) => array_key_exists($v, $this->cities()) ? null : $fail('Choose a city from the list.')],
            'cod' => ['required', 'numeric', 'min:0'],
            'weight' => ['required', 'integer', 'min:1', 'max:100000'],
            'pieces' => ['required', 'integer', 'min:1', 'max:50'],
            'description' => ['required', 'string', 'max:250'],
            'instructions' => ['nullable', 'string', 'max:250'],
        ], [], ['connectionId' => 'courier', 'cod' => 'amount to collect', 'weight' => 'weight (grams)']);

        abort_if($connection === null, 422);

        $shipment = Shipments::book($connection, $sale, [
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'city_name' => $this->cities()[$this->city],
            'cod' => (float) $this->cod,
            'weight_grams' => (int) $this->weight,
            'pieces' => (int) $this->pieces,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'whatsapp_order_id' => $this->whatsappOrderId,
            'store_order_id' => $this->storeOrderId,
        ], Auth::id());

        $this->toastSuccess('Booked with '.$shipment->courier->label().'. Tracking number '.$shipment->tracking_number.'.');
        $this->redirectRoute('shipments.index', ['focus' => $shipment->id], navigate: true);
    }

    public function with(): array
    {
        $sale = Sale::with('items')->findOrFail($this->saleId);

        return [
            'sale' => $sale,
            'connections' => CourierConnection::query()->where('status', 'connected')->orderBy('id')->get(),
            'cityOptions' => $this->cities(),
            'existing' => Shipment::where('sale_id', $sale->id)->where('status', '!=', 'cancelled')->first(),
            'source' => $this->whatsappOrderId ? 'WhatsApp order' : ($this->storeOrderId ? 'online store order' : null),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales.show', $saleId) }}" :back-label="$sale->invoiceNumber()" subtitle="Book this sale's parcel with your own courier account">
            Book a Courier
        </x-ui.page-header>
    </x-slot>

    @if ($existing)
        <x-ui.empty-state title="Already booked" :description="'This sale is booked with '.$existing->courier->label().', tracking number '.$existing->tracking_number.'. Cancel that parcel first to book again.'">
            <x-ui.button href="{{ route('shipments.index', ['focus' => $existing->id]) }}" wire:navigate>See the parcel</x-ui.button>
        </x-ui.empty-state>
    @elseif ($connections->isEmpty())
        <x-ui.empty-state title="Connect a courier first" description="Add your own Leopards or PostEx account in Settings › Integrations › Couriers, then come back to book.">
            @if (auth()->user()->hasAccessTo('settings-integrations'))
                <x-ui.button href="{{ route('settings.index', ['tab' => 'integrations', 'section' => 'couriers']) }}" wire:navigate>Connect a courier</x-ui.button>
            @endif
        </x-ui.empty-state>
    @else
        <form wire:submit="book" class="grid grid-cols-1 gap-6 lg:grid-cols-3" data-booking-form>
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card title="Deliver to" :description="$source ? 'Filled in from the '.$source.'. Check it before booking.' : 'Filled in from the customer. Check it before booking.'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Name" name="name" for="name">
                            <x-ui.input wire:model="name" id="name" />
                        </x-ui.field>
                        <x-ui.field label="Mobile number" name="phone" for="phone">
                            <x-ui.input wire:model="phone" id="phone" type="tel" inputmode="tel" />
                        </x-ui.field>
                        <x-ui.field label="Address" name="address" for="address" class="sm:col-span-2" help="House, street and area, as the rider needs it">
                            <x-ui.input wire:model="address" id="address" />
                        </x-ui.field>
                        <x-ui.field label="City" name="city" for="city" help="From the courier's own list">
                            <div wire:key="city-{{ $connectionId }}-{{ $city }}">
                                <x-ui.select wire:model="city" id="city">
                                    <option value="">Choose a city</option>
                                    @foreach ($cityOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>
                    </div>
                </x-ui.card>

                <x-ui.card title="Parcel">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.field label="What's inside" name="description" for="description" class="sm:col-span-3">
                            <x-ui.input wire:model="description" id="description" />
                        </x-ui.field>
                        <x-ui.field label="Weight (grams)" name="weight" for="weight">
                            <x-ui.input wire:model="weight" id="weight" type="number" min="1" step="1" />
                        </x-ui.field>
                        <x-ui.field label="Pieces" name="pieces" for="pieces">
                            <x-ui.input wire:model="pieces" id="pieces" type="number" min="1" step="1" />
                        </x-ui.field>
                        <x-ui.field label="Note for the rider" name="instructions" for="instructions" help="Optional">
                            <x-ui.input wire:model="instructions" id="instructions" />
                        </x-ui.field>
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                <x-ui.card title="Courier & cash">
                    <div class="space-y-4">
                        <x-ui.field label="Courier" name="connectionId" for="connectionId">
                            <x-ui.select wire:model.live="connectionId" id="connectionId">
                                @foreach ($connections as $option)
                                    <option value="{{ $option->id }}">{{ $option->courier->label() }}{{ $option->credential('environment') === 'staging' ? ' (test account)' : '' }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Cash to collect (COD)" name="cod" for="cod" :help="'Rs '.number_format($sale->amountDue(), 2).' is still owed on '.$sale->invoiceNumber().'. Put 0 if it\'s already paid.'">
                            <x-ui.input wire:model="cod" id="cod" type="number" min="0" step="1" />
                        </x-ui.field>
                        <x-ui.error :messages="$errors->get('courier')" />
                        <x-ui.button type="submit" variant="accent" class="w-full justify-center" wire:loading.attr="disabled" wire:target="book">
                            <span wire:loading.remove wire:target="book">Book parcel</span>
                            <span wire:loading wire:target="book">Booking…</span>
                        </x-ui.button>
                        <p class="text-xs text-ink-faint">The courier collects the cash and pays it to you. Record it against the sale when they do.</p>
                    </div>
                </x-ui.card>
            </div>
        </form>
    @endif
</div>
