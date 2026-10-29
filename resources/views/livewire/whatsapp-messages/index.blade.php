<?php

use App\Enums\NotificationType;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Repair;
use App\ReportQueries\UdhaarReport;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customer Messages')] class extends Component
{
    use Toasts, WithPagination;

    #[Url]
    public string $tab = 'reminders';

    public ?int $previewCustomerId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->whatsapp_enabled, 403);

        if (! in_array($this->tab, ['reminders', 'repairs', 'log'], true)) {
            $this->tab = 'reminders';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['reminders', 'repairs', 'log'], true) ? $tab : 'reminders';
        $this->resetPage();
    }

    public function previewReminder(int $customerId): void
    {
        $this->previewCustomerId = Customer::findOrFail($customerId)->id;
        $this->dispatch('open-modal', name: 'reminder-preview');
    }

    public function sendReminder(int $customerId, CustomerNotifications $notifications): void
    {
        $customer = Customer::findOrFail($customerId);

        try {
            $notification = $notifications->paymentReminder($customer, Auth::id());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'reminder-preview');
        $this->previewCustomerId = null;

        $notification->wasSent()
            ? $this->toastSuccess("Payment reminder sent to {$customer->name}.")
            : $this->toastError("Reminder not sent: {$notification->error}");
    }

    public function notifyRepairReady(int $repairId, CustomerNotifications $notifications): void
    {
        $repair = Repair::findOrFail($repairId);

        try {
            $notification = $notifications->repairReady($repair, Auth::id());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $notification->wasSent()
            ? $this->toastSuccess('Customer notified that the repair is ready.')
            : $this->toastError("Not sent: {$notification->error}");
    }

    public function with(): array
    {
        $notifications = app(CustomerNotifications::class);
        $data = ['connected' => CustomerMessenger::whatsappConnection(Auth::user()->shop_id) !== null];

        if ($this->tab === 'reminders') {
            $rows = (new UdhaarReport)->rows()->filter(fn (array $row) => $row['balance'] > 0)->values();
            $customerIds = $rows->pluck('customer.id');

            $data['reminderRows'] = $rows;
            $data['lastReminders'] = CustomerNotification::query()
                ->whereIn('customer_id', $customerIds)
                ->where('type', NotificationType::PaymentReminder->value)
                ->where('status', 'sent')
                ->selectRaw('customer_id, MAX(sent_at) as last_sent_at')
                ->groupBy('customer_id')
                ->pluck('last_sent_at', 'customer_id');
            $data['preview'] = $this->previewCustomerId
                ? $notifications->paymentReminderPreview(Customer::findOrFail($this->previewCustomerId))
                : null;
            $data['previewCustomer'] = $this->previewCustomerId ? Customer::find($this->previewCustomerId) : null;
        }

        if ($this->tab === 'repairs') {
            $repairs = Repair::query()
                ->with('customer')
                ->whereNotNull('customer_id')
                ->latest()
                ->paginate(20);

            $data['repairs'] = $repairs;
            $data['repairNotices'] = CustomerNotification::query()
                ->where('type', NotificationType::RepairReady->value)
                ->where('notifiable_type', (new Repair)->getMorphClass())
                ->whereIn('notifiable_id', $repairs->pluck('id'))
                ->latest('id')
                ->get()
                ->unique('notifiable_id')
                ->keyBy('notifiable_id');
        }

        if ($this->tab === 'log') {
            $data['notifications'] = CustomerNotification::query()->with(['customer', 'contact', 'sentBy'])->latest('id')->paginate(25);
        }

        return $data;
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Customer Messages</h1>
    </x-slot>

    @if (! $connected)
        <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
            WhatsApp is not connected yet. Connect it in Settings → Integrations to send messages.
        </div>
    @endif

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach (['reminders' => 'Payment reminders', 'repairs' => 'Repairs ready', 'log' => 'Sent messages'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
                'border-brand-600 text-brand-700' => $tab === $key,
                'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'reminders')
        <p class="mb-3 text-sm text-slate-500">Customers with an outstanding balance (same total as the Udhaar screen). The reminder lists every item separately so the customer knows exactly what each amount is for. One reminder per customer every {{ CustomerNotifications::REMINDER_COOLDOWN_HOURS / 24 }} days.</p>

        @if ($reminderRows->isEmpty())
            <x-ui.empty-state title="No outstanding balances" description="Nobody owes the shop anything right now." />
        @else
            <x-ui.table :headers="['Customer', 'Phone', 'Total due', 'Last reminder', '']">
                @foreach ($reminderRows as $row)
                    @php($customer = $row['customer'])
                    <x-ui.table-row wire:key="reminder-{{ $customer->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">{{ $customer->name }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $customer->phone ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($row['balance'], 2) }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ isset($lastReminders[$customer->id]) ? \Illuminate\Support\Carbon::parse($lastReminders[$customer->id])->diffForHumans() : 'Never' }}</x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            @if (PhoneNumber::normalize($customer->phone))
                                <x-ui.button size="sm" variant="secondary" wire:click="previewReminder({{ $customer->id }})">Preview &amp; send</x-ui.button>
                            @else
                                <span class="text-xs text-slate-400">No phone number</span>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif

        <x-ui.modal name="reminder-preview" maxWidth="lg">
            @if ($preview && $previewCustomer)
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-slate-900">Reminder to {{ $previewCustomer->name }}</h2>
                    <pre class="mt-4 whitespace-pre-wrap rounded-lg bg-emerald-50 p-4 font-sans text-sm text-slate-800">{{ $preview['text'] }}</pre>
                    <p class="mt-2 text-xs text-slate-500">Sent in the customer's chosen language. If they haven't messaged in 24 hours, WhatsApp's approved reminder template is used with the same items.</p>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'reminder-preview' })">Cancel</x-ui.button>
                        <x-ui.button wire:click="sendReminder({{ $previewCustomer->id }})" wire:loading.attr="disabled">Send on WhatsApp</x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.modal>
    @elseif ($tab === 'repairs')
        @if ($repairs->isEmpty())
            <x-ui.empty-state title="No repairs with a customer yet" description="Repairs linked to a customer appear here so you can tell them it's ready." />
        @else
            <x-ui.table :headers="['Receipt', 'Date', 'Customer', 'Device', 'Remaining', 'Ready notice', '']">
                @foreach ($repairs as $repair)
                    @php($notice = $repairNotices[$repair->id] ?? null)
                    <x-ui.table-row wire:key="repair-{{ $repair->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">{{ $repair->receiptNumber() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $repair->created_at->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $repair->customer?->name }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $repair->categoryLabel() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $repair->amountOwed() > 0 ? 'Rs '.number_format($repair->amountOwed(), 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell>
                            @if ($notice?->wasSent())
                                <x-ui.badge variant="success">Sent {{ $notice->sent_at->diffForHumans() }}</x-ui.badge>
                            @elseif ($notice)
                                <x-ui.badge variant="danger" title="{{ $notice->error }}">Failed</x-ui.badge>
                            @else
                                —
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            @if (PhoneNumber::normalize($repair->customer?->phone))
                                <x-ui.button size="sm" variant="secondary" wire:click="notifyRepairReady({{ $repair->id }})" wire:confirm="Tell {{ $repair->customer?->name }} the repair is ready for pickup?">
                                    {{ $notice?->wasSent() ? 'Send again' : 'Notify ready' }}
                                </x-ui.button>
                            @else
                                <span class="text-xs text-slate-400">No phone number</span>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $repairs->links() }}</div>
        @endif
    @else
        @if ($notifications->isEmpty())
            <x-ui.empty-state title="No messages sent yet" />
        @else
            <x-ui.table :headers="['When', 'Type', 'To', 'Via', 'Status', 'By']">
                @foreach ($notifications as $notification)
                    <x-ui.table-row wire:key="notification-{{ $notification->id }}">
                        <x-ui.table-cell>{{ $notification->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $notification->type->label() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $notification->customer?->name ?? $notification->contact?->displayName() ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $notification->delivery_mode === 'template' ? 'Template' : ($notification->delivery_mode === 'session' ? 'Chat' : '—') }}</x-ui.table-cell>
                        <x-ui.table-cell>
                            <x-ui.badge :variant="$notification->wasSent() ? 'success' : 'danger'" title="{{ $notification->error }}">{{ $notification->status->label() }}</x-ui.badge>
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $notification->sentBy?->name ?? 'Automatic' }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $notifications->links() }}</div>
        @endif
    @endif
</div>
