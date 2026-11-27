<?php

namespace App\Livewire\Concerns;

use App\Actions\SaleDeliveries;
use App\Actions\Shipments;
use App\Enums\CourierKey;
use App\Enums\OnlineTool;
use App\Enums\SaleSource;
use App\Models\Sale;
use App\Support\ParcelRefusals;
use App\Support\PhoneNumber;
use App\Support\StockAvailability;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The register's online side (livewire/sales/create), for an order taken from
 * a message or a call and sent by courier. Each guard names what it guards:
 *   - takesDelivery() (OnlineTool::Delivery): the channel (O1), who it goes to
 *     (O1), a delivery charge (O2), and a parcel already booked on the courier's
 *     own portal (O1), plus earlier refusals to that phone (O5);
 *   - holdsForOrders() (OnlineTool::OrderHolds): stock that pending WhatsApp
 *     and online-store orders hold can't be sold again here (O3).
 * For any other shop every hook hands back nothing, so the register sells
 * exactly as it always has.
 *
 * The register calls in through deliveryChargeValue() in total(),
 * deliveryRules() and onlineCustomer() while validating, assertNotHeldForPendingOrders()
 * before the sale, onlineSaleArguments() for CreateSale, recordParcel() after
 * it, and onlineViewData() in with().
 */
trait SellsOnline
{
    /** Where the order came from (SaleSource), for an online shop. */
    public string $channel = '';

    /** Sent by courier: who it goes to, and what they pay for delivery. */
    public bool $withDelivery = false;

    /** @var array{name: string, phone: string, address: string, city: string} */
    public array $delivery = ['name' => '', 'phone' => '', 'address' => '', 'city' => ''];

    public string $deliveryCharge = '';

    /** Already booked on the courier's own portal: which courier and its tracking number. */
    public string $parcelCourier = '';

    public string $parcelCourierName = '';

    public string $parcelTracking = '';

    /** @var array<string, bool> this request's tool checks */
    protected array $onlineMemo = [];

    protected function usesOnlineTool(OnlineTool $tool): bool
    {
        return $this->onlineMemo[$tool->value] ??= (bool) Auth::user()->shop?->usesTool($tool);
    }

    protected function takesDelivery(): bool
    {
        return $this->usesOnlineTool(OnlineTool::Delivery);
    }

    protected function holdsForOrders(): bool
    {
        return $this->usesOnlineTool(OnlineTool::OrderHolds);
    }

    /** The channels an online seller takes orders on; In person last, for the odd walk-in. */
    public static function onlineChannels(): array
    {
        return [SaleSource::Social, SaleSource::WhatsApp, SaleSource::Online, SaleSource::InStore];
    }

    protected function startOnline(): void
    {
        if ($this->takesDelivery()) {
            $this->channel = SaleSource::Social->value;
            $this->withDelivery = true;
        }
    }

    protected function sendsParcel(): bool
    {
        return $this->takesDelivery() && $this->withDelivery && $this->exchangeSaleId === null;
    }

    /** The delivery charge the customer pays, part of the total (O2). */
    public function deliveryChargeValue(): float
    {
        return $this->sendsParcel() && is_numeric($this->deliveryCharge) ? round(max(0.0, (float) $this->deliveryCharge), 2) : 0.0;
    }

    /** @return array<string, mixed> */
    protected function deliveryRules(): array
    {
        if (! $this->takesDelivery()) {
            return [];
        }

        $rules = ['channel' => ['required', Rule::in(array_map(fn (SaleSource $source) => $source->value, self::onlineChannels()))]];

        if (! $this->sendsParcel()) {
            return $rules;
        }

        return $rules + SaleDeliveries::rules() + [
            'deliveryCharge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'parcelCourier' => ['nullable', Rule::enum(CourierKey::class)],
            'parcelCourierName' => [$this->parcelCourier === CourierKey::Other->value && $this->parcelTracking !== '' ? 'required' : 'nullable', 'string', 'max:60'],
            'parcelTracking' => [$this->parcelCourier !== '' ? 'required' : 'nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    protected function deliveryAttributes(): array
    {
        return ['delivery.name' => 'name', 'delivery.phone' => 'phone', 'delivery.address' => 'address', 'delivery.city' => 'city', 'deliveryCharge' => 'delivery charge', 'parcelTracking' => 'tracking number', 'parcelCourierName' => 'courier name'];
    }

    /**
     * Cash on delivery leaves money unpaid, which needs a customer: the one with
     * this phone, else a new one from the delivery details (SaleDeliveries).
     */
    protected function onlineCustomer(bool $isFullyPaid): void
    {
        if (! $this->sendsParcel() || $isFullyPaid || $this->customerId !== '' || ! PhoneNumber::isPakistaniMobile($this->delivery['phone'] ?? '') || blank($this->delivery['name'] ?? '')) {
            return;
        }

        $this->customerId = (string) SaleDeliveries::customerFor(Auth::user()->shop_id, $this->delivery)->id;
    }

    /** Refuses stock pending WhatsApp and online-store orders hold (O3). */
    protected function assertNotHeldForPendingOrders(array $lines): void
    {
        if (! $this->holdsForOrders()) {
            return;
        }

        StockAvailability::assertFree($lines, fn (int $productId) => "cart.{$productId}.quantity");
    }

    /** @return array{attributes: array<string, string>, deliveryCharge: float, delivery: ?array} the online parts of CreateSale's arguments */
    protected function onlineSaleArguments(): array
    {
        if (! $this->takesDelivery()) {
            return ['attributes' => [], 'deliveryCharge' => 0.0, 'delivery' => null];
        }

        return [
            'attributes' => ['source' => $this->channel],
            'deliveryCharge' => $this->deliveryChargeValue(),
            'delivery' => $this->sendsParcel() ? $this->delivery : null,
        ];
    }

    /** A parcel already booked on the courier's portal, recorded against the new sale. */
    protected function recordParcel(Sale $sale): void
    {
        if (! $this->sendsParcel() || $this->parcelCourier === '' || trim($this->parcelTracking) === '') {
            return;
        }

        Shipments::recordManual($sale, CourierKey::from($this->parcelCourier), $this->parcelCourierName, $this->parcelTracking, $this->delivery, Auth::id());
    }

    /** @return array<string, mixed> the register view's online data (empty for every other shop) */
    protected function onlineViewData(): array
    {
        if (! $this->takesDelivery()) {
            return ['takesDelivery' => false];
        }

        return [
            'takesDelivery' => true,
            'channels' => self::onlineChannels(),
            'couriers' => CourierKey::typedIn(),
            'refusals' => $this->sendsParcel() ? ParcelRefusals::forPhone(Auth::user()->shop_id, $this->delivery['phone'] ?? null) : null,
        ];
    }
}
