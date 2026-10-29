<?php

namespace App\Support;

use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePayment;
use App\Models\SimSale;
use App\Models\WalletLoad;
use Illuminate\Support\Collection;

class CustomerDuesStatement
{
    public function __construct(public readonly Customer $customer)
    {
    }

    public static function for(Customer $customer): self
    {
        return new self($customer);
    }

    public function udhaarBalance(): float
    {
        return $this->customer->fresh()->udhaarBalance();
    }

    public function items(): Collection
    {
        $customerId = $this->customer->id;
        $rows = collect();

        Sale::where('customer_id', $customerId)->withSum('payments', 'amount')->get()
            ->each(function (Sale $sale) use ($rows) {
                if ($sale->amountDue() > 0) {
                    $rows->push($this->row('sale', $sale->id, $sale->invoiceNumber(), $this->saleItemsLabel($sale), $sale->created_at, $sale->amountDue()));
                }
            });

        WalletLoad::where('customer_id', $customerId)->where('direction', 'cash_in')->get()
            ->each(function (WalletLoad $load) use ($rows) {
                if ($load->amountOwed() > 0) {
                    $rows->push($this->row('wallet_load', $load->id, $load->receiptNumber(), $load->provider.' wallet load', $load->created_at, $load->amountOwed()));
                }
            });

        BalanceLoad::where('customer_id', $customerId)->get()
            ->each(function (BalanceLoad $load) use ($rows) {
                if ($load->amountOwed() > 0) {
                    $rows->push($this->row('balance_load', $load->id, $load->receiptNumber(), $load->network.' balance load', $load->created_at, $load->amountOwed()));
                }
            });

        BillPayment::where('customer_id', $customerId)->with('billCategory')->get()
            ->each(function (BillPayment $payment) use ($rows) {
                if ($payment->amountOwed() > 0) {
                    $rows->push($this->row('bill_payment', $payment->id, $payment->receiptNumber(), ($payment->billCategory?->name ?? 'Utility').' bill', $payment->created_at, $payment->amountOwed()));
                }
            });

        Repair::where('customer_id', $customerId)->get()
            ->each(function (Repair $repair) use ($rows) {
                if ($repair->amountOwed() > 0) {
                    $rows->push($this->row('repair', $repair->id, $repair->receiptNumber(), trim($repair->categoryLabel().' repair'.($repair->description ? ' ('.mb_strimwidth($repair->description, 0, 40, '…').')' : '')), $repair->created_at, $repair->amountOwed()));
                }
            });

        NadraVerification::where('customer_id', $customerId)->get()
            ->each(function (NadraVerification $verification) use ($rows) {
                if ($verification->amountOwed() > 0) {
                    $rows->push($this->row('nadra_verification', $verification->id, $verification->receiptNumber(), 'NADRA verification', $verification->created_at, $verification->amountOwed()));
                }
            });

        SimSale::where('customer_id', $customerId)->get()
            ->each(function (SimSale $simSale) use ($rows) {
                if ($simSale->amountOwed() > 0) {
                    $rows->push($this->row('sim_sale', $simSale->id, $simSale->receiptNumber(), $simSale->network.' SIM', $simSale->created_at, $simSale->amountOwed()));
                }
            });

        ServicePayment::where('customer_id', $customerId)->with(['serviceCategory', 'serviceItem'])->get()
            ->each(function (ServicePayment $payment) use ($rows) {
                if ($payment->amountOwed() > 0) {
                    $rows->push($this->row('service_payment', $payment->id, $payment->receiptNumber(), $payment->serviceItem?->name ?? $payment->serviceCategory?->name ?? 'Service', $payment->created_at, $payment->amountOwed()));
                }
            });

        return $rows->sortByDesc('date')->values();
    }

    public function total(): float
    {
        return $this->udhaarBalance() + (float) $this->items()->sum('amountDue');
    }

    public function hasDues(): bool
    {
        return $this->total() > 0;
    }

    protected function row(string $source, int $id, string $reference, string $label, $date, float $amount): array
    {
        return [
            'source' => $source,
            'id' => $id,
            'reference' => $reference,
            'label' => $label,
            'date' => $date,
            'amountDue' => $amount,
        ];
    }

    protected function saleItemsLabel(Sale $sale): string
    {
        $names = SaleItem::where('sale_id', $sale->id)->pluck('product_name')->unique()->values();

        if ($names->isEmpty()) {
            return 'Purchase';
        }

        return $names->take(2)->implode(', ').($names->count() > 2 ? ' +'.($names->count() - 2).' more' : '');
    }
}
