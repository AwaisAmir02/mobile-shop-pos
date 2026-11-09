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
        abort_unless(Auth::user()->shop?->whatsapp_messaging_enabled, 403);

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
        <x-ui.page-header>Customer Messages</x-ui.page-header>
    </x-slot>

    <x-billing.limit-notice limit="whatsapp_messages_per_month" />

    @if (! $connected)
        <div class="mb-4 rounded-lg bg-warning-soft p-3 text-sm text-warning">
            WhatsApp is not connected yet. Connect it in Settings → Integrations to send messages.
        </div>
    @endif

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line">
        @foreach (['reminders' => 'Payment reminders', 'repairs' => 'Repairs ready', 'log' => 'Sent messages'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
                'border-primary text-link' => $tab === $key,
                'border-transparent text-ink-faint hover:text-ink-muted' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'reminders')
        <p class="mb-3 text-sm text-ink-faint">Customers with an outstanding balance (same total as the Udhaar screen). The reminder lists every item separately so the customer knows exactly what each amount is for. One reminder per customer every {{ CustomerNotifications::REMINDER_COOLDOWN_HOURS / 24 }} days.</p>

        @if ($reminderRows->isEmpty())
            <x-ui.empty-state title="No outstanding balances" description="Nobody owes the shop anything right now." />
        @else
            <x-ui.table stack :headers="['Customer', 'Phone', 'Total due', 'Last reminder', '']">
                @foreach ($reminderRows as $row)
                    @php($customer = $row['customer'])
                    <x-ui.table-row wire:key="reminder-{{ $customer->id }}">
                        <x-ui.table-cell primary class="font-medium text-ink">{{ $customer->name }}</x-ui.table-cell>
                        <x-ui.table-cell label="Phone">{{ $customer->phone ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Total due" class="font-semibold text-ink">Rs {{ number_format($row['balance'], 2) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Last reminder">{{ isset($lastReminders[$customer->id]) ? \Illuminate\Support\Carbon::parse($lastReminders[$customer->id])->diffForHumans() : 'Never' }}</x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            @if (PhoneNumber::normalize($customer->phone))
                                <x-ui.button size="sm" variant="secondary" wire:click="previewReminder({{ $customer->id }})">Preview &amp; send</x-ui.button>
                            @else
                                <span class="text-xs text-ink-faint">No phone number</span>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif

        <x-ui.modal name="reminder-preview" maxWidth="lg">
            @if ($preview && $previewCustomer)
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-ink">Reminder to {{ $previewCustomer->name }}</h2>
                    <pre class="mt-4 whitespace-pre-wrap rounded-lg bg-success-soft p-4 font-sans text-sm text-ink">{{ $preview['text'] }}</pre>
                    <p class="mt-2 text-xs text-ink-faint">Sent in the customer's chosen language. If they haven't messaged in 24 hours, WhatsApp's approved reminder template is used with the same items.</p>
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
            <x-ui.table stack :headers="['Receipt', 'Date', 'Customer', 'Device', 'Remaining', 'Ready notice', '']">
                @foreach ($repairs as $repair)
                    @php($notice = $repairNotices[$repair->id] ?? null)
                    <x-ui.table-row wire:key="repair-{{ $repair->id }}">
                        <x-ui.table-cell primary class="font-medium text-ink">{{ $repair->receiptNumber() }}</x-ui.table-cell>
                        <x-ui.table-cell label="Date">{{ $repair->created_at->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell label="Customer">{{ $repair->customer?->name }}</x-ui.table-cell>
                        <x-ui.table-cell label="Device">{{ $repair->categoryLabel() }}</x-ui.table-cell>
                        <x-ui.table-cell label="Remaining">{{ $repair->amountOwed() > 0 ? 'Rs '.number_format($repair->amountOwed(), 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Ready notice">
                            @if ($notice?->wasSent())
                                <x-ui.badge variant="success">Sent {{ $notice->sent_at->diffForHumans() }}</x-ui.badge>
                            @elseif ($notice)
                                <x-ui.badge variant="danger" title="{{ $notice->error }}">Failed</x-ui.badge>
                            @else
                                —
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            @if (PhoneNumber::normalize($repair->customer?->phone))
                                <x-ui.button size="sm" variant="secondary" wire:click="notifyRepairReady({{ $repair->id }})" confirm="Tell {{ $repair->customer?->name }} the repair is ready for pickup?">
                                    {{ $notice?->wasSent() ? 'Send again' : 'Notify ready' }}
                                </x-ui.button>
                            @else
                                <span class="text-xs text-ink-faint">No phone number</span>
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
            <x-ui.table stack :headers="['When', 'Type', 'To', 'Via', 'Status', 'By']">
                @foreach ($notifications as $notification)
                    <x-ui.table-row wire:key="notification-{{ $notification->id }}">
                        <x-ui.table-cell primary>{{ $notification->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                        <x-ui.table-cell label="Type">{{ $notification->type->label() }}</x-ui.table-cell>
                        <x-ui.table-cell label="To">{{ $notification->customer?->name ?? $notification->contact?->displayName() ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Via">{{ $notification->delivery_mode === 'template' ? 'Template' : ($notification->delivery_mode === 'session' ? 'Chat' : '—') }}</x-ui.table-cell>
                        <x-ui.table-cell label="Status">
                            <x-ui.badge :variant="$notification->wasSent() ? 'success' : 'danger'" title="{{ $notification->error }}">{{ $notification->status->label() }}</x-ui.badge>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="By">{{ $notification->sentBy?->name ?? 'Automatic' }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $notifications->links() }}</div>
        @endif
    @endif
</div>
