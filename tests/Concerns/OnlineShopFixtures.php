<?php

namespace Tests\Concerns;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\SaleDeliveries;
use App\Actions\Shipments;
use App\Enums\CourierKey;
use App\Enums\ShipmentStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Support\MoneyFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * An online seller (Online P1 tests): the owner signed in (password secret),
 * a bank account, a vendor and one product (Lawn Suit, Rs 3,500, cost Rs 2,000)
 * with 10 in stock from one batch. Parcels go by courier, cash on delivery.
 */
trait OnlineShopFixtures
{
    protected Shop $shop;

    protected User $owner;

    protected ShopAccount $bank;

    protected Product $suit;

    protected function openOnlineShop(string $category = 'social_seller', array $attributes = []): void
    {
        // Midday UTC: the shop's day (Karachi) and the server's day agree.
        Carbon::setTestNow('2026-11-20 08:00:00');

        $this->shop = Shop::create(['name' => 'Style Hub '.$category, 'business_category' => $category] + $attributes);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('secret')]);
        $this->actingAs($this->owner);
        $this->bank = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        $this->suit = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Lawn Suit', 'price' => 3500, 'cost_price' => 2000, 'stock_quantity' => 0, 'details' => ['category' => 'Women'], 'whatsapp_listed' => true]);
        $vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Faisalabad Mills']);
        CreateStockIn::handle([['product_id' => $this->suit->id, 'quantity' => 10, 'cost_price' => 2000, 'selling_price' => 3500]], $vendor->id, 'paid', null, '2026-11-01', null, $this->owner->id, paymentMethod: 'cash');
        $this->suit->refresh();
    }

    /** @return array{name: string, phone: string, address: string, city: string} */
    protected function deliveryTo(string $phone = '0321 4445556', string $name = 'Ayesha Khan'): array
    {
        return ['name' => $name, 'phone' => $phone, 'address' => 'House 12, Street 4, Model Town', 'city' => 'Lahore'];
    }

    /** A cash-on-delivery order straight through CreateSale: nothing paid, delivery charged. */
    protected function codSale(int $quantity = 1, float $deliveryCharge = 250, string $phone = '0321 4445556'): Sale
    {
        $delivery = $this->deliveryTo($phone);

        return CreateSale::handle(
            lines: [['product_id' => $this->suit->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0, customerId: SaleDeliveries::customerFor($this->shop->id, $delivery)->id, userId: $this->owner->id,
            saleDate: now()->toDateString(), amountPaid: 0, attributes: ['source' => 'social'], deliveryCharge: $deliveryCharge, delivery: $delivery,
        );
    }

    /** The sale's parcel, typed in from the courier's portal, moved on to $status. */
    protected function parcel(Sale $sale, ShipmentStatus $status = ShipmentStatus::Delivered, CourierKey $courier = CourierKey::Leopards, ?string $tracking = null): Shipment
    {
        $shipment = Shipments::recordManual($sale, $courier, null, $tracking ?? 'LE'.str_pad((string) $sale->id, 8, '0', STR_PAD_LEFT), [], $this->owner->id);

        return $status === ShipmentStatus::Booked ? $shipment : Shipments::markManual($shipment, $status);
    }

    protected function customerOf(Sale $sale): Customer
    {
        return Customer::findOrFail($sale->customer_id);
    }

    /** The shop's own JazzCash merchant account (sandbox), paying into the bank account. */
    protected function jazzcash(): \App\Models\PaymentGatewayConnection
    {
        return \App\Models\PaymentGatewayConnection::create(['shop_id' => $this->shop->id, 'gateway' => \App\Enums\PaymentGateway::JazzCash, 'shop_account_id' => $this->bank->id, 'status' => \App\Enums\ConnectionStatus::Connected,
            'credentials' => ['merchant_id' => 'MC12345', 'password' => 'pass-123', 'integrity_salt' => 'salt-xyz', 'environment' => 'sandbox']]);
    }

    /** What JazzCash posts back, signed with the shop's salt. */
    protected function jazzcashResult(\App\Models\CustomerPayment $payment, string $code = '000', ?int $paisa = null, string $salt = 'salt-xyz'): array
    {
        $fields = ['pp_Amount' => (string) ($paisa ?? \App\Payments\JazzCash::paisa((float) $payment->amount)), 'pp_BillReference' => 'C'.$payment->customer_id, 'pp_Language' => 'EN', 'pp_MerchantID' => 'MC12345',
            'pp_ResponseCode' => $code, 'pp_ResponseMessage' => $code === '000' ? 'Thank you for Using JazzCash' : 'Transaction failed', 'pp_RetreivalReferenceNo' => '260116123456',
            'pp_TxnCurrency' => 'PKR', 'pp_TxnDateTime' => '20261116120000', 'pp_TxnRefNo' => $payment->reference, 'pp_TxnType' => 'MWALLET', 'pp_Version' => '1.1'];

        return $fields + ['pp_SecureHash' => \App\Payments\JazzCash::sign($fields, $salt)];
    }

    /**
     * A Daraz Seller Center file, as CSV, in a temp folder.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float>>  $rows
     */
    protected function darazFile(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'daraz').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /** All money in less money out on Cash & Balances, every account and till, all time. */
    protected function netMoneyMoved(): float
    {
        return round((float) (new MoneyFlow($this->shop->id))->movements()->sum('amount'), 2);
    }
}
