<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\NadraVerification;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\SimSale;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceDiscountVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setupShopAndOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A', 'branding_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    public function test_sales_invoice_hides_discount_column_and_summary_row_when_discount_is_zero(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Case', 'price' => 500, 'stock_quantity' => 10, 'details' => []]);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Case', 'product_type' => 'accessory', 'unit_price' => 500, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items'])])->render();

        $this->assertStringNotContainsString('<th class="text-right">Discount</th>', $html);
        $this->assertStringNotContainsString('<td>Discount</td>', $html);
    }

    public function test_sales_invoice_shows_discount_column_and_summary_row_when_discount_is_greater_than_zero(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1000, 'stock_quantity' => 10, 'details' => []]);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 1000, 'discount_amount' => 100, 'total' => 900]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Charger', 'product_type' => 'accessory', 'unit_price' => 1000, 'quantity' => 1, 'discount_amount' => 100, 'line_total' => 900]);

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items'])])->render();

        $this->assertStringContainsString('<th class="text-right">Discount</th>', $html);
        $this->assertStringContainsString('<td>Discount</td>', $html);
        $this->assertStringContainsString('Rs 100.00', $html);
    }

    public function test_sales_show_screen_hides_discount_when_zero_and_shows_when_greater_than_zero(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $saleZero = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        $saleDiscount = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 50, 'total' => 450]);

        $htmlZero = Livewire::test('sales.show', ['sale' => $saleZero])->html();
        $this->assertStringNotContainsString('Discount</span>', $htmlZero);

        $htmlDiscount = Livewire::test('sales.show', ['sale' => $saleDiscount])->html();
        $this->assertStringContainsString('Discount</span>', $htmlDiscount);
        $this->assertStringContainsString('Rs 50.00', $htmlDiscount);
    }

    public function test_repair_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $repairZero = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Fix', 'amount' => 500, 'discount' => 0, 'total' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]);
        $repairDiscount = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Fix 2', 'amount' => 600, 'discount' => 50, 'total' => 550, 'payment_status' => 'paid', 'amount_paid' => 550]);

        $htmlZero = view('pdf.repair-receipt', ['repair' => $repairZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.repair-receipt', ['repair' => $repairDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('50.00', $htmlDiscount);
    }

    public function test_sim_sale_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $saleZero = SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200]);
        $saleDiscount = SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234568', 'amount' => 200, 'fee' => 0, 'discount' => 20, 'total' => 180]);

        $htmlZero = view('pdf.sim-sale-receipt', ['sale' => $saleZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.sim-sale-receipt', ['sale' => $saleDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('20.00', $htmlDiscount);
    }

    public function test_wallet_load_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'JazzCash', 'provider_type' => 'jazzcash']);

        $loadZero = WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'shop_account_id' => $account->id, 'provider' => 'JazzCash', 'direction' => 'cash_in', 'account_name' => 'Ali', 'account_number' => '03001111111', 'amount' => 1000, 'fee' => 20, 'fee_included_in_amount' => false, 'discount' => 0, 'total' => 1020, 'net_amount' => 1000]);
        $loadDiscount = WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'shop_account_id' => $account->id, 'provider' => 'JazzCash', 'direction' => 'cash_in', 'account_name' => 'Ali', 'account_number' => '03001111111', 'amount' => 1000, 'fee' => 20, 'fee_included_in_amount' => false, 'discount' => 10, 'total' => 1010, 'net_amount' => 1000]);

        $htmlZero = view('pdf.wallet-load-receipt', ['load' => $loadZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.wallet-load-receipt', ['load' => $loadDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('10.00', $htmlDiscount);
    }

    public function test_balance_load_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Easyload', 'provider_type' => 'jazz']);

        $loadZero = BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'shop_account_id' => $account->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'fee' => 0, 'discount' => 0, 'total' => 500, 'payment_status' => PaymentStatus::Paid]);
        $loadDiscount = BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'shop_account_id' => $account->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'fee' => 0, 'discount' => 25, 'total' => 475, 'payment_status' => PaymentStatus::Paid]);

        $htmlZero = view('pdf.balance-load-receipt', ['load' => $loadZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.balance-load-receipt', ['load' => $loadDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('25.00', $htmlDiscount);
    }

    public function test_bill_payment_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $paymentZero = BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'consumer_number' => '12345', 'consumer_name' => 'Ali', 'amount' => 2500, 'fee' => 30, 'discount' => 0, 'total' => 2530]);
        $paymentDiscount = BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'consumer_number' => '12346', 'consumer_name' => 'Ali', 'amount' => 2500, 'fee' => 30, 'discount' => 15, 'total' => 2515]);

        $htmlZero = view('pdf.bill-payment-receipt', ['payment' => $paymentZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.bill-payment-receipt', ['payment' => $paymentDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('15.00', $htmlDiscount);
    }

    public function test_nadra_verification_receipt_hides_discount_row_when_zero_and_shows_when_positive(): void
    {
        [$shop, $owner] = $this->setupShopAndOwner();
        $verZero = NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03001234567', 'cnic_number' => '42101-1234567-1', 'amount' => 300, 'fee' => 50, 'discount' => 0, 'total' => 350]);
        $verDiscount = NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03001234567', 'cnic_number' => '42101-1234567-1', 'amount' => 300, 'fee' => 50, 'discount' => 20, 'total' => 330]);

        $htmlZero = view('pdf.nadra-verification-receipt', ['verification' => $verZero])->render();
        $this->assertStringNotContainsString('<td class="label">Discount</td>', $htmlZero);

        $htmlDiscount = view('pdf.nadra-verification-receipt', ['verification' => $verDiscount])->render();
        $this->assertStringContainsString('<td class="label">Discount</td>', $htmlDiscount);
        $this->assertStringContainsString('20.00', $htmlDiscount);
    }
}
