<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\PaymentGatewayConnection;
use App\Services\WhatsApp\CustomerMessenger;
use App\Services\WhatsApp\CustomerNotifications;
use App\Support\CustomerDuesStatement;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * On a customer's udhaar page: their own ledger link to share, and a payment
 * reminder by WhatsApp or SMS. Both carry the link.
 */
new class extends Component
{
    use Toasts;

    #[Locked]
    public int $customerId;

    public function mount(Customer $customer): void
    {
        $this->customerId = $customer->id;
    }

    protected function customer(): Customer
    {
        return Customer::findOrFail($this->customerId);
    }

    public function newLink(): void
    {
        $this->customer()->rotateLedgerToken();
        $this->toastSuccess('New link made. The old one no longer opens.');
    }

    public function remind(string $channel, CustomerNotifications $notifications): void
    {
        $customer = $this->customer();

        try {
            if ($channel === 'sms') {
                $notification = $notifications->paymentReminderSms($customer, Auth::id());
            } else {
                abort_unless(Auth::user()->hasAccessTo('whatsapp-messages'), 403);
                $notification = $notifications->paymentReminder($customer, Auth::id());
            }
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $notification->wasSent()
            ? $this->toastSuccess('Reminder sent to '.$customer->name.($channel === 'sms' ? ' by SMS.' : ' on WhatsApp.'))
            : $this->toastError('Reminder not sent: '.$notification->error);
    }

    public function with(CustomerNotifications $notifications): array
    {
        $customer = $this->customer();
        $shop = Auth::user()->shop;
        $total = round(CustomerDuesStatement::for($customer)->total(), 2);
        $link = $customer->ledgerUrl();
        $canPay = PaymentGatewayConnection::activeFor($customer->shop_id) !== null;
        $waPhone = $customer->phone ? preg_replace('/\D+/', '', (string) \App\Support\PhoneNumber::normalize($customer->phone)) : null;
        $shareText = $customer->name.', your account with '.$shop->invoiceDisplayName().': '.$link;

        return [
            'link' => $link,
            'total' => $total,
            'canPay' => $canPay,
            'shareUrl' => 'https://wa.me/'.($waPhone ?: '').'?text='.rawurlencode($shareText),
            'whatsapp' => $shop->whatsapp_messaging_enabled && Auth::user()->hasAccessTo('whatsapp-messages') && CustomerMessenger::whatsappConnection($shop->id) !== null,
            'sms' => $notifications->smsRemindersAvailable(),
            'smsUsed' => $notifications->smsRemindersAvailable() ? $notifications->smsSentThisMonth($shop->id) : 0,
            'smsCap' => (int) config('reminders.sms.monthly_cap_per_shop'),
            'lastReminder' => $notifications->lastReminder($customer),
        ];
    }
}; ?>

<x-ui.card class="mb-6" title="Customer's own link" :description="$canPay ? 'They can open it any time to see what they owe and their history, and pay with JazzCash. No login needed.' : 'They can open it any time to see what they owe and their history. No login needed.'" data-share-and-remind>
    <div x-data="{ copied: false }" class="flex flex-wrap items-center gap-2">
        <input type="text" readonly value="{{ $link }}" x-ref="link" x-on:focus="$el.select()" aria-label="Link to the customer's own ledger" class="min-w-0 flex-1 basis-64 rounded-lg border-line-strong bg-surface-sunken/50 py-2 text-sm text-ink-muted" data-ledger-link>
        <x-ui.button size="sm" variant="secondary" type="button" x-on:click="navigator.clipboard?.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1500)">
            <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
        </x-ui.button>
        <x-ui.button size="sm" variant="secondary" href="{{ $link }}" target="_blank">Open</x-ui.button>
        <x-ui.button size="sm" variant="secondary" href="{{ $shareUrl }}" target="_blank">Share on WhatsApp</x-ui.button>
        <x-ui.button size="sm" variant="ghost" wire:click="newLink" confirm="Make a new link? The one you shared before stops working.">New link</x-ui.button>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
        <span class="mr-auto text-sm text-ink-muted">
            Payment reminder
            @if ($lastReminder)
                <span class="text-ink-faint">· last sent {{ $lastReminder->sent_at->diffForHumans() }}{{ $lastReminder->delivery_mode === 'sms' ? ' by SMS' : '' }}</span>
            @endif
        </span>
        @if ($whatsapp)
            <x-ui.button size="sm" wire:click="remind('whatsapp')" :disabled="$total <= 0" wire:loading.attr="disabled" wire:target="remind">Send on WhatsApp</x-ui.button>
        @endif
        @if ($sms)
            <x-ui.button size="sm" variant="secondary" wire:click="remind('sms')" :disabled="$total <= 0" wire:loading.attr="disabled" wire:target="remind" data-send-sms>Send by SMS</x-ui.button>
            <span class="basis-full text-right text-xs text-ink-faint">{{ $smsUsed }} of {{ $smsCap }} SMS reminders used this month</span>
        @endif
        @if (! $whatsapp && ! $sms)
            <span class="text-xs text-ink-faint">Share the link above, or turn on WhatsApp messaging to send reminders from here.</span>
        @endif
    </div>
</x-ui.card>
