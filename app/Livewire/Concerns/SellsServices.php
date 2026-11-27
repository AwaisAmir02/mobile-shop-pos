<?php

namespace App\Livewire\Concerns;

use App\Actions\Appointments;
use App\Actions\CustomerDeposits;
use App\Actions\GiftVouchers;
use App\Actions\JobCards;
use App\Actions\Memberships;
use App\Enums\AppointmentStatus;
use App\Enums\MembershipKind;
use App\Enums\RecordStatus;
use App\Enums\SaleLineKind;
use App\Enums\SalePaymentMethod;
use App\Enums\ServiceTradeTool;
use App\Models\Appointment;
use App\Models\CustomerDeposit;
use App\Models\Employee;
use App\Models\JobCard;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The register for a service business (Services group): services off the
 * menu, labour, memberships and package sessions on the same bill as
 * products, each with who did it; gift vouchers and deposits paying part of
 * it; and billing a booking (?appointment=ID), a job card (?job_card=ID) or
 * a renewal (?renew=MEMBERSHIP_ID).
 *
 * Guards: servicesOn() (a shop whose trade uses the service menu or job
 * cards) covers every hook; usesTool() narrows memberships, vouchers and
 * deposits. Every other shop's register is unchanged: the hooks add nothing
 * (no lines, 0 to the subtotal, nothing prepaid).
 *
 * Products stay in $cart (keyed by product); service lines live here in
 * $serviceLines (keyed by a short id), and CreateSale gets them as services:.
 */
trait SellsServices
{
    /** @var array<string, array{kind: string, service_item_id: ?int, membership_plan_id: ?int, membership_id: ?int, name: string, unit_price: string, quantity: int, discount: string, performed_by: string}> */
    public array $serviceLines = [];

    public string $serviceSearch = '';

    public string $voucherCode = '';

    /** @var list<array{id: int, code: string, left: float}> */
    public array $vouchers = [];

    /** @var array<int, bool> deposit id => use it */
    public array $useDeposits = [];

    public ?int $appointmentId = null;

    public ?int $jobCardId = null;

    /** Memoized per request: whether this shop sells services. */
    protected ?bool $servicesOnCache = null;

    public function servicesOn(): bool
    {
        if ($this->servicesOnCache === null) {
            $shop = Auth::user()?->shop;
            $this->servicesOnCache = $shop !== null && ($shop->usesTool(ServiceTradeTool::ServiceMenu) || $shop->usesTool(ServiceTradeTool::JobCards));
        }

        return $this->servicesOnCache;
    }

    protected function serviceTool(ServiceTradeTool $tool): bool
    {
        return $this->servicesOn() && (bool) Auth::user()?->shop?->usesTool($tool);
    }

    /** From mount(): a booking, job card or renewal to bill. */
    protected function startServices(): void
    {
        if (! $this->servicesOn()) {
            return;
        }

        if (($id = request()->query('appointment')) && ctype_digit((string) $id)) {
            $this->billAppointment((int) $id);
        } elseif (($id = request()->query('job_card')) && ctype_digit((string) $id)) {
            $this->billJobCard((int) $id);
        } elseif (($id = request()->query('renew')) && ctype_digit((string) $id)) {
            $membership = Membership::find((int) $id);

            if ($membership?->membership_plan_id && $membership->plan?->is_active) {
                $this->customerId = (string) $membership->customer_id;
                $this->addMembershipPlan($membership->membership_plan_id);
            }
        }
    }

    public function addService(int $serviceItemId): void
    {
        $item = ServiceItem::where('is_active', true)->findOrFail($serviceItemId);
        $this->serviceLines[(string) Str::ulid()] = $this->newServiceLine('service', $item->name, (string) (float) $item->price, serviceItemId: $item->id);
        $this->serviceSearch = '';
    }

    /** A line typed in: labour on a job, a service not on the menu. */
    public function addCustomService(): void
    {
        $this->serviceLines[(string) Str::ulid()] = $this->newServiceLine('service', '', '');
    }

    public function addMembershipPlan(int $planId): void
    {
        abort_unless($this->serviceTool(ServiceTradeTool::Memberships), 404);
        $plan = MembershipPlan::where('is_active', true)->findOrFail($planId);
        $this->serviceLines[(string) Str::ulid()] = $this->newServiceLine('membership', $plan->name, (string) (float) $plan->price, planId: $plan->id);
        $this->serviceSearch = '';
    }

    public function removeServiceLine(string $key): void
    {
        unset($this->serviceLines[$key]);
    }

    /** Takes a service line out of one of the customer's packages (free on this bill), or back to paying. */
    public function usePackage(string $key, ?int $membershipId): void
    {
        if (! isset($this->serviceLines[$key])) {
            return;
        }

        $line = &$this->serviceLines[$key];

        if ($membershipId === null) {
            $line['kind'] = 'service';
            $line['membership_id'] = null;
            $line['unit_price'] = (string) (float) (ServiceItem::find($line['service_item_id'])?->price ?? 0);
        } else {
            $membership = Membership::findOrFail($membershipId);
            abort_unless((string) $membership->customer_id === $this->customerId, 404);
            $line['kind'] = 'package';
            $line['membership_id'] = $membership->id;
            $line['unit_price'] = '0';
            $line['discount'] = '0';
        }

        unset($line);
    }

    public function applyVoucher(): void
    {
        abort_unless($this->serviceTool(ServiceTradeTool::GiftVouchers), 404);
        $voucher = GiftVouchers::find(Auth::user()->shop_id, $this->voucherCode);
        $problem = GiftVouchers::problem($voucher, $this->saleDate ?: now()->toDateString());

        if ($problem === null && collect($this->vouchers)->contains('id', $voucher->id)) {
            $problem = 'That voucher is already on this bill.';
        }

        if ($problem !== null) {
            $this->addError('voucherCode', $problem);

            return;
        }

        $this->resetErrorBag('voucherCode');
        $this->vouchers[] = ['id' => $voucher->id, 'code' => $voucher->code, 'left' => $voucher->remaining()];
        $this->voucherCode = '';
    }

    public function removeVoucher(int $index): void
    {
        unset($this->vouchers[$index]);
        $this->vouchers = array_values($this->vouchers);
    }

    public function servicesSubtotal(): float
    {
        return round(array_sum(array_map(fn (array $line) => $this->serviceLineTotal($line), $this->serviceLines)), 2);
    }

    public function serviceLineTotal(array $line): float
    {
        if ($line['kind'] === 'package') {
            return 0.0;
        }

        $gross = (is_numeric($line['unit_price']) ? (float) $line['unit_price'] : 0.0) * max(1, (int) $line['quantity']);

        return round(max(0.0, $gross - (is_numeric($line['discount']) ? (float) $line['discount'] : 0.0)), 2);
    }

    /**
     * Vouchers and deposits paying part of the bill: each takes what it can of
     * what's left after the trade-in, exchange and store credit.
     *
     * @return list<array{method: string, id: int, amount: float, label: string}>
     */
    public function prepaidParts(): array
    {
        if (! $this->servicesOn()) {
            return [];
        }

        $room = round(max(0.0, $this->total() - $this->tradeInValue() - $this->exchangeValue() - $this->creditValue()), 2);
        $parts = [];

        foreach ($this->vouchers as $voucher) {
            $amount = round(min($room, (float) $voucher['left']), 2);

            if ($amount > 0) {
                $parts[] = ['method' => SalePaymentMethod::GiftVoucher->value, 'id' => (int) $voucher['id'], 'amount' => $amount, 'label' => 'Gift voucher '.$voucher['code']];
                $room = round($room - $amount, 2);
            }
        }

        foreach ($this->heldDeposits() as $deposit) {
            if (empty($this->useDeposits[$deposit->id])) {
                continue;
            }

            $amount = round(min($room, $deposit->remaining()), 2);

            if ($amount > 0) {
                $parts[] = ['method' => SalePaymentMethod::Deposit->value, 'id' => $deposit->id, 'amount' => $amount, 'label' => 'Deposit '.$deposit->number()];
                $room = round($room - $amount, 2);
            }
        }

        return $parts;
    }

    public function servicePrepaidValue(): float
    {
        return round(array_sum(array_column($this->prepaidParts(), 'amount')), 2);
    }

    /** The lines as CreateSale takes them. */
    protected function serviceSaleLines(): array
    {
        return array_values(array_map(fn (array $line) => [
            'kind' => $line['kind'],
            'service_item_id' => $line['service_item_id'],
            'membership_plan_id' => $line['membership_plan_id'],
            'membership_id' => $line['membership_id'],
            'name' => $line['name'],
            'unit_price' => $line['unit_price'],
            'quantity' => (int) $line['quantity'],
            'discount' => $line['discount'],
            'performed_by' => $line['performed_by'] !== '' ? (int) $line['performed_by'] : null,
        ], $this->serviceLines));
    }

    /** @return array<string, array<int, mixed>> */
    protected function serviceRules(): array
    {
        $rules = [];

        foreach (array_keys($this->serviceLines) as $key) {
            $rules["serviceLines.$key.name"] = ['required', 'string', 'max:255'];
            $rules["serviceLines.$key.unit_price"] = ['required', 'numeric', 'min:0', 'max:9999999.99'];
            $rules["serviceLines.$key.discount"] = ['required', 'numeric', 'min:0'];
            $rules["serviceLines.$key.quantity"] = ['required', 'integer', 'min:1', 'max:999'];
        }

        return $rules;
    }

    /** A different customer: package sessions of the old one go back to paid lines, and their deposits are let go. */
    protected function servicesCustomerChanged(): void
    {
        $this->useDeposits = [];

        foreach ($this->serviceLines as $key => $line) {
            if ($line['kind'] === 'package') {
                $this->usePackage($key, null);
            }
        }
    }

    /** After CreateSale: the booking or job card billed is done. */
    protected function afterServicesSale(Sale $sale): void
    {
        if ($this->appointmentId !== null && ($appointment = Appointment::find($this->appointmentId))) {
            Appointments::billed($appointment, $sale);
        }

        if ($this->jobCardId !== null && ($job = JobCard::find($this->jobCardId))) {
            JobCards::billed($job, $sale);
        }
    }

    protected function servicesViewData(): array
    {
        if (! $this->servicesOn()) {
            return ['servicesOn' => false];
        }

        $search = trim($this->serviceSearch);
        $customerId = ctype_digit($this->customerId) ? (int) $this->customerId : null;
        $date = $this->saleDate ?: now()->toDateString();

        return [
            'servicesOn' => true,
            'serviceResults' => ServiceItem::query()->where('is_active', true)->whereNotNull('price')
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')->limit($search !== '' ? 8 : 12)->get(),
            'planResults' => $this->serviceTool(ServiceTradeTool::Memberships)
                ? MembershipPlan::query()->where('is_active', true)->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orderBy('name')->limit(6)->get()
                : collect(),
            'staff' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'packageChoices' => $customerId !== null && $this->serviceTool(ServiceTradeTool::Memberships)
                ? collect($this->serviceLines)->map(fn (array $line) => $line['kind'] === 'membership' ? collect() : Memberships::usablePackages($customerId, $line['service_item_id'], $date))->all()
                : [],
            'heldDeposits' => $this->heldDeposits(),
            'vouchersOn' => $this->serviceTool(ServiceTradeTool::GiftVouchers),
            'billing' => $this->appointmentId ? Appointment::find($this->appointmentId)?->number() : ($this->jobCardId ? JobCard::find($this->jobCardId)?->number() : null),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, CustomerDeposit> */
    protected function heldDeposits(): \Illuminate\Support\Collection
    {
        if (! $this->serviceTool(ServiceTradeTool::Deposits) || ! ctype_digit($this->customerId)) {
            return collect();
        }

        return CustomerDeposit::query()->where('customer_id', (int) $this->customerId)->where('status', RecordStatus::Active->value)->orderBy('id')->get()->filter(fn (CustomerDeposit $d) => $d->remaining() > 0)->values();
    }

    protected function billAppointment(int $id): void
    {
        $appointment = Appointment::with('services')->find($id);

        if ($appointment === null || ! $appointment->status->isOpen()) {
            return;
        }

        $this->appointmentId = $appointment->id;
        $this->customerId = $appointment->customer_id !== null ? (string) $appointment->customer_id : '';

        foreach (Appointments::billLines($appointment) as $line) {
            $this->serviceLines[(string) Str::ulid()] = $this->newServiceLine('service', $line['name'], $line['unit_price'], serviceItemId: $line['service_item_id'], performer: $line['performed_by']);
        }

        foreach ($appointment->deposits as $deposit) {
            $this->useDeposits[$deposit->id] = true;
        }
    }

    protected function billJobCard(int $id): void
    {
        $job = JobCard::with(['lines', 'deposits'])->find($id);

        if ($job === null || ! $job->status->isOpen()) {
            return;
        }

        $this->jobCardId = $job->id;
        $this->customerId = (string) $job->customer_id;
        $lines = JobCards::billLines($job);

        foreach ($lines['products'] as $part) {
            $product = Product::find($part['product_id']);

            if ($product === null) {
                continue;
            }

            $line = $this->cartLineFor($product, $part['quantity']);

            if (round((float) $line['list_price'], 2) !== round($part['unit_price'], 2)) {
                $line['custom_price'] = (string) $part['unit_price'];
            }

            $this->cart[$product->id] = $line;
        }

        foreach ($lines['services'] as $line) {
            $this->serviceLines[(string) Str::ulid()] = $this->newServiceLine('service', $line['name'], $line['unit_price'], serviceItemId: $line['service_item_id'], performer: $line['performed_by'], quantity: $line['quantity']);
        }

        foreach ($job->deposits as $deposit) {
            $this->useDeposits[$deposit->id] = true;
        }
    }

    protected function newServiceLine(string $kind, string $name, string $price, ?int $serviceItemId = null, ?int $planId = null, string $performer = '', int $quantity = 1): array
    {
        return [
            'kind' => $kind,
            'service_item_id' => $serviceItemId,
            'membership_plan_id' => $planId,
            'membership_id' => null,
            'name' => $name,
            'unit_price' => $price,
            'quantity' => $quantity,
            'discount' => '0',
            'performed_by' => $performer,
        ];
    }
}
