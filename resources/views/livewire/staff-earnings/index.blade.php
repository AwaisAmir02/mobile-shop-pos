<?php

use App\Enums\CommissionKind;
use App\Enums\CommissionTarget;
use App\Livewire\Concerns\Toasts;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\StaffCommissionRule;
use App\ReportQueries\StaffEarningsReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Staff Earnings (Services group): commission rules, and what each person
 * earned in a month (App\Support\StaffEarnings, the same figure Payroll puts
 * on the payslip).
 */
new #[Layout('layouts.app')] #[Title('Staff Earnings')] class extends Component
{
    use Toasts;

    #[Url(except: '')]
    public string $month = '';

    #[Url(as: 'staff', except: null)]
    public ?int $staffId = null;

    public ?int $ruleId = null;
    public string $ruleEmployeeId = '';
    public string $ruleTarget = 'all_services';
    public string $ruleTargetId = '';
    public string $ruleKind = 'percent';
    public string $ruleValue = '';
    public bool $ruleActive = true;

    public function boot(): void
    {
        \App\Support\BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        $this->month = $this->month !== '' ? $this->month : now('Asia/Karachi')->format('Y-m');
    }

    public function openRule(?int $id = null): void
    {
        $this->resetErrorBag();
        $rule = $id ? StaffCommissionRule::findOrFail($id) : null;
        $this->ruleId = $rule?->id;
        $this->ruleEmployeeId = (string) ($rule?->employee_id ?? '');
        $this->ruleTarget = $rule?->target->value ?? 'all_services';
        $this->ruleTargetId = (string) ($rule?->target_id ?? '');
        $this->ruleKind = $rule?->kind->value ?? 'percent';
        $this->ruleValue = $rule ? (string) (float) $rule->value : '';
        $this->ruleActive = $rule?->is_active ?? true;
        $this->dispatch('open-modal', name: 'rule-form');
    }

    public function saveRule(): void
    {
        $shopId = Auth::user()->shop_id;
        $target = CommissionTarget::tryFrom($this->ruleTarget);
        $table = match ($target) {
            CommissionTarget::Service => 'service_items',
            CommissionTarget::ServiceCategory => 'service_categories',
            CommissionTarget::MembershipPlan => 'membership_plans',
            default => null,
        };

        $this->validate([
            'ruleEmployeeId' => ['nullable', Rule::exists('employees', 'id')->where('shop_id', $shopId)],
            'ruleTarget' => ['required', Rule::enum(CommissionTarget::class)],
            'ruleTargetId' => $table ? ['required', Rule::exists($table, 'id')->where('shop_id', $shopId)] : ['nullable'],
            'ruleKind' => ['required', Rule::enum(CommissionKind::class)],
            'ruleValue' => ['required', 'numeric', 'min:0', $this->ruleKind === 'percent' ? 'max:100' : 'max:9999999'],
        ], [], ['ruleTargetId' => 'what it pays on', 'ruleValue' => 'rate']);

        $attributes = ['employee_id' => $this->ruleEmployeeId !== '' ? (int) $this->ruleEmployeeId : null, 'target' => $target, 'target_id' => $table ? (int) $this->ruleTargetId : null, 'kind' => $this->ruleKind, 'value' => $this->ruleValue, 'is_active' => $this->ruleActive];
        $this->ruleId ? StaffCommissionRule::findOrFail($this->ruleId)->update($attributes) : StaffCommissionRule::create($attributes);

        $this->dispatch('close-modal', name: 'rule-form');
        $this->toastSuccess('Rule saved.');
    }

    public function deleteRule(int $id): void
    {
        StaffCommissionRule::findOrFail($id)->delete();
        $this->toastSuccess('Rule removed. Months already paid keep what they were paid; any difference comes on the next payslip.');
    }

    public function with(): array
    {
        $first = \Carbon\Carbon::parse(($this->month ?: now()->format('Y-m')).'-01');
        $report = new StaffEarningsReport(Auth::user()->shop_id, $first->toDateString(), $first->copy()->endOfMonth()->toDateString());
        $names = fn (string $table) => \Illuminate\Support\Facades\DB::table($table)->where('shop_id', Auth::user()->shop_id)->pluck('name', 'id');

        return [
            'report' => $report,
            'byStaff' => $report->byStaff(),
            'detail' => $this->staffId ? $report->lines()->where('employee_id', $this->staffId) : collect(),
            'rules' => StaffCommissionRule::query()->with('employee')->orderByRaw('employee_id IS NULL DESC')->orderBy('id')->get(),
            'staff' => Employee::query()->orderBy('name')->get(['id', 'name']),
            'targets' => ['service' => ServiceItem::query()->orderBy('name')->get(['id', 'name']), 'service_category' => ServiceCategory::query()->orderBy('name')->get(['id', 'name']), 'membership_plan' => MembershipPlan::query()->orderBy('name')->get(['id', 'name'])],
            'targetNames' => ['service' => $names('service_items'), 'service_category' => $names('service_categories'), 'membership_plan' => $names('membership_plans')],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('staff.index') }}" back-label="Staff" subtitle="Commission on what each person did, after discounts and returns">Staff Earnings</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-end gap-3">
        <x-ui.field label="Month" for="month" class="w-48"><x-ui.input wire:model.live="month" id="month" type="month" /></x-ui.field>
        <p class="pb-2 text-sm text-ink-muted">Total commission: <strong class="text-ink" data-commission-total>Rs {{ number_format($report->total(), 2) }}</strong></p>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="By staff" :padding="false" data-earnings-by-staff>
            @if ($byStaff->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-faint">No commission this month. Say who did each service on the bill, and add a rule.</p>
            @else
                <x-ui.table :headers="['Staff', 'Lines', 'Earned on', 'Commission']">
                    @foreach ($byStaff as $row)
                        <tr wire:key="earn-{{ $row['employee']?->id }}" wire:click="$set('staffId', {{ $row['employee']?->id ?? 'null' }})" class="cursor-pointer hover:bg-surface-sunken">
                            <td class="px-4 py-2.5 text-sm font-semibold text-ink">{{ $row['employee']?->name ?? 'Removed staff' }}</td>
                            <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $row['count'] }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums text-ink-muted">Rs {{ number_format($row['base'], 2) }}</td>
                            <td class="px-4 py-2.5 text-sm font-semibold tabular-nums text-ink">Rs {{ number_format($row['commission'], 2) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Commission rules" description="A rule for one person beats one for everyone; the most exact one wins (one service, then its category, then all services)." :padding="false" data-commission-rules>
            <x-slot name="actions"><x-ui.button size="sm" wire:click="openRule" data-new-rule>New Rule</x-ui.button></x-slot>
            @if ($rules->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-faint">No rules yet, so no commission is worked out.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($rules as $rule)
                        <li wire:key="rule-{{ $rule->id }}" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                            <span>
                                <span class="font-semibold text-ink">{{ $rule->employee?->name ?? 'Everyone' }}</span>
                                · {{ $rule->target->label() }}{{ $rule->target_id ? ': '.($targetNames[$rule->target->value][$rule->target_id] ?? '?') : '' }}
                                · <span class="font-semibold text-ink">{{ $rule->rateLabel() }}</span>
                                @if (! $rule->is_active) <x-ui.badge>Off</x-ui.badge>@endif
                            </span>
                            <span class="flex gap-1">
                                <x-ui.button size="sm" variant="secondary" wire:click="openRule({{ $rule->id }})">Edit</x-ui.button>
                                <x-ui.button size="sm" variant="danger-ghost" wire:click="deleteRule({{ $rule->id }})" confirm="Remove this rule?">Remove</x-ui.button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    @if ($staffId && $detail->isNotEmpty())
        <x-ui.card title="Lines" class="mt-6" :padding="false" data-earning-lines>
            <x-ui.table :headers="['Date', 'Bill', 'Line', 'Earned on', 'Rate', 'Commission']">
                @foreach ($detail as $line)
                    <tr>
                        <td class="px-4 py-2 text-sm text-ink-muted">{{ \Carbon\Carbon::parse($line['date'])->format('d M') }}</td>
                        <td class="px-4 py-2 text-sm"><a href="{{ route('sales.show', $line['sale_id']) }}" class="text-link hover:underline" wire:navigate>{{ $line['invoice'] }}</a></td>
                        <td class="px-4 py-2 text-sm text-ink">{{ $line['name'] }}</td>
                        <td class="px-4 py-2 text-sm tabular-nums text-ink-muted">Rs {{ number_format($line['base'], 2) }}</td>
                        <td class="px-4 py-2 text-sm text-ink-muted">{{ $line['rule'] ?? 'No rule' }}</td>
                        <td class="px-4 py-2 text-sm font-semibold tabular-nums text-ink">Rs {{ number_format($line['commission'], 2) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.modal name="rule-form" max-width="md">
        <form wire:submit="saveRule" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">{{ $ruleId ? 'Edit Rule' : 'New Rule' }}</h2>
            <x-ui.field label="Who" name="ruleEmployeeId" for="ruleEmployee">
                <x-ui.select wire:model="ruleEmployeeId" id="ruleEmployee">
                    <option value="">Everyone</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Pays on" name="ruleTarget" for="ruleTarget">
                <x-ui.select wire:model.live="ruleTarget" id="ruleTarget">
                    @foreach (\App\Enums\CommissionTarget::cases() as $target)
                        <option value="{{ $target->value }}">{{ $target->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            @if (isset($targets[$ruleTarget]))
                <x-ui.field label="Which" name="ruleTargetId" for="ruleTargetId">
                    <x-ui.select wire:model="ruleTargetId" id="ruleTargetId" wire:key="target-{{ $ruleTarget }}">
                        <option value="">Choose…</option>
                        @foreach ($targets[$ruleTarget] as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Kind" name="ruleKind" for="ruleKind">
                    <x-ui.select wire:model="ruleKind" id="ruleKind">
                        @foreach (\App\Enums\CommissionKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Rate" name="ruleValue" for="ruleValue"><x-ui.input wire:model="ruleValue" id="ruleValue" type="number" min="0" step="0.01" /></x-ui.field>
            </div>
            <x-ui.checkbox wire:model="ruleActive" label="In use" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'rule-form' })">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
        </form>
    </x-ui.modal>
</div>
