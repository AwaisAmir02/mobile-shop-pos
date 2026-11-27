<?php

namespace App\ReportQueries;

use App\Enums\SaleLineKind;
use App\Models\Appointment;
use App\Models\CheckIn;
use App\Models\Employee;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

/**
 * A client's visit history on their page: the services, memberships and
 * package sessions on their bills (with who did each), their bookings, and
 * their latest check-ins.
 */
class ClientVisits
{
    public function __construct(private readonly int $customerId) {}

    /** @return Collection<int, array{date: string, sale_id: int, invoice: string, name: string, kind: SaleLineKind, by: ?string, amount: float}> */
    public function services(int $limit = 30): Collection
    {
        $items = SaleItem::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.customer_id', $this->customerId)
            ->where('sales.status', '!=', 'cancelled')
            ->where('sale_items.line_kind', '!=', SaleLineKind::Product->value)
            ->orderByDesc('sales.created_at')
            ->orderByDesc('sale_items.id')
            ->limit($limit)
            ->get(['sale_items.*', 'sales.created_at as sold_at']);

        $staff = Employee::withoutGlobalScopes()->whereIn('id', $items->pluck('performed_by')->filter()->unique())->pluck('name', 'id');

        return $items->map(fn (SaleItem $item) => [
            'date' => \Carbon\Carbon::parse($item->sold_at)->timezone('Asia/Karachi')->toDateString(),
            'sale_id' => (int) $item->sale_id,
            'invoice' => 'INV-'.str_pad((string) $item->sale_id, 6, '0', STR_PAD_LEFT),
            'name' => $item->product_name,
            'kind' => $item->line_kind,
            'by' => $item->performed_by ? ($staff[$item->performed_by] ?? null) : null,
            'amount' => (float) $item->line_total,
        ]);
    }

    /** @return Collection<int, Appointment> upcoming first, then the latest past */
    public function appointments(int $limit = 10): Collection
    {
        return Appointment::withoutGlobalScopes()->with(['services', 'employee'])->where('customer_id', $this->customerId)->orderByDesc('appointment_date')->orderByDesc('id')->limit($limit)->get();
    }

    /** @return Collection<int, CheckIn> */
    public function checkIns(int $limit = 10): Collection
    {
        return CheckIn::withoutGlobalScopes()->where('customer_id', $this->customerId)->latest('checked_in_at')->limit($limit)->get();
    }
}
