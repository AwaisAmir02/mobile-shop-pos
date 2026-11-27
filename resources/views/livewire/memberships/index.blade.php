<?php

use App\Actions\BiometricDevices;
use App\Actions\Memberships;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipFreeze;
use App\ReportQueries\MembershipRoster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Members (Services group): who holds a membership or package, where each
 * stands today and what they owe. Renew opens New Sale with the same
 * membership; freeze pauses one; a member can be sent to the fingerprint
 * device under their member number.
 */
new #[Layout('layouts.app')] #[Title('Members')] class extends Component
{
    use Toasts;

    #[Url(except: '')]
    public string $filter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $freezeId = null;
    public string $freezeFrom = '';
    public string $freezeTo = '';
    public string $freezeReason = '';

    public function boot(): void
    {
        \App\Support\BranchContext::current()->shopWideForRequest();
    }

    public function openFreeze(int $membershipId): void
    {
        $this->freezeId = $membershipId;
        $this->freezeFrom = now('Asia/Karachi')->toDateString();
        $this->freezeTo = now('Asia/Karachi')->addDays(6)->toDateString();
        $this->freezeReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'freeze-form');
    }

    public function freeze(): void
    {
        $this->validate(['freezeFrom' => ['required', 'date'], 'freezeTo' => ['required', 'date']]);
        Memberships::freeze(Membership::findOrFail($this->freezeId), $this->freezeFrom, $this->freezeTo, $this->freezeReason ?: null, Auth::id());
        $this->dispatch('close-modal', name: 'freeze-form');
        $this->toastSuccess('Frozen. The end date moved out by the same days.');
    }

    public function unfreeze(int $freezeId): void
    {
        Memberships::unfreeze(MembershipFreeze::findOrFail($freezeId));
        $this->toastSuccess('Freeze removed.');
    }

    public function sendToDevice(int $customerId): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Biometric), 404);

        try {
            $count = BiometricDevices::sendMember(Customer::findOrFail($customerId));
            $this->toastSuccess('Sent to '.$count.' '.str('device')->plural($count).'. Enrol the finger on the device under member no. '.Customer::find($customerId)->member_no.'.');
        } catch (ValidationException $e) {
            $this->toastError(collect($e->errors())->flatten()->first());
        }
    }

    public function with(): array
    {
        $roster = new MembershipRoster(Auth::user()->shop_id, now('Asia/Karachi')->toDateString());

        return [
            'rows' => $roster->rows($this->filter ?: null, trim($this->search)),
            'counts' => $roster->counts(),
            'freezing' => $this->freezeId ? Membership::with('freezes')->find($this->freezeId) : null,
            'biometricOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Biometric),
            'shopName' => Auth::user()->shop->invoiceDisplayName(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Who is a member, where they stand today, and what they owe">Members</x-ui.page-header>
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['' => 'All', 'active' => 'Active', 'expiring' => 'Ending this week', 'expired' => 'Expired', 'frozen' => 'Frozen', 'dues' => 'Owe money'] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" @class(['rounded-full border px-3 py-1.5 text-sm font-semibold', 'border-link bg-primary-soft text-link' => $filter === $value, 'border-line text-ink-muted hover:border-line-strong' => $filter !== $value]) data-member-filter="{{ $value ?: 'all' }}">
                {{ $label }} · {{ $counts[$value ?: 'all'] }}
            </button>
        @endforeach
        <x-ui.input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, phone or member no." class="ml-auto w-64" aria-label="Search members" />
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="No members here" description="A membership or package starts when it's sold on a bill under New Sale." />
    @else
        <x-ui.table :headers="['Member', 'Membership', 'Standing', 'Owes', '']" data-members>
            @foreach ($rows as $row)
                @php($membership = $row['membership'])
                <tr wire:key="member-{{ $row['customer']->id }}">
                    <td class="px-4 py-2.5 text-sm">
                        <a href="{{ route('customers.show', $row['customer']) }}" class="font-semibold text-ink hover:underline" wire:navigate>{{ $row['customer']->name }}</a>
                        <span class="block text-xs text-ink-faint">{{ $row['customer']->phone }}{{ $row['customer']->member_no ? ' · no. '.$row['customer']->member_no : '' }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-sm text-ink-muted">
                        {{ $membership?->plan_name }}
                        <span class="block text-xs text-ink-faint">
                            @if ($membership?->ends_on) until {{ $membership->ends_on->format('d M Y') }} @endif
                            @if ($row['sessions_left'] !== null) · {{ $row['sessions_left'] }} sessions left @endif
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-sm"><x-ui.badge :variant="match ($row['standing']) { \App\Enums\MemberStanding::Active => 'success', \App\Enums\MemberStanding::Frozen => 'brand', default => 'danger' }">{{ $row['standing']->label() }}</x-ui.badge></td>
                    <td class="px-4 py-2.5 text-sm tabular-nums {{ $row['dues'] > 0 ? 'font-semibold text-danger' : 'text-ink-faint' }}">{{ $row['dues'] > 0 ? 'Rs '.number_format($row['dues'], 2) : '—' }}</td>
                    <td class="px-4 py-2.5 text-right text-sm">
                        <div class="flex flex-wrap justify-end gap-1">
                            @if ($membership?->membership_plan_id)
                                <x-ui.button size="sm" href="{{ route('sales.index', ['renew' => $membership->id]) }}" data-renew="{{ $membership->id }}">Renew</x-ui.button>
                            @endif
                            @if ($membership && $membership->ends_on && ($link = \App\Actions\JobCards::whatsappLink($row['customer']->phone, \App\Actions\ServiceReminders::renewalText($membership->setRelation('customer', $row['customer']), $shopName))) && in_array($filter, ['expiring', 'expired'], true))
                                <x-ui.button size="sm" variant="secondary" href="{{ $link }}" target="_blank" data-remind-renewal="{{ $membership->id }}">Remind</x-ui.button>
                            @endif
                            @if ($membership && $membership->max_freeze_days > 0 && $row['standing'] === \App\Enums\MemberStanding::Active)
                                <x-ui.button size="sm" variant="secondary" wire:click="openFreeze({{ $membership->id }})">Freeze</x-ui.button>
                            @endif
                            @if ($biometricOn)
                                <x-ui.button size="sm" variant="secondary" wire:click="sendToDevice({{ $row['customer']->id }})" data-send-device="{{ $row['customer']->id }}">To device</x-ui.button>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="freeze-form" max-width="sm">
        <form wire:submit="freeze" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Freeze {{ $freezing?->plan_name }}</h2>
            @if ($freezing)
                <p class="text-sm text-ink-muted">Up to {{ $freezing->max_freeze_days }} days; {{ $freezing->daysFrozen() }} used.</p>
                @foreach ($freezing->freezes as $f)
                    <p class="text-sm text-ink">{{ $f->from_date->format('d M') }}–{{ $f->to_date->format('d M') }} <button type="button" wire:click="unfreeze({{ $f->id }})" class="text-xs font-semibold text-link hover:underline">Remove</button></p>
                @endforeach
            @endif
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="From" name="freezeFrom" for="freezeFrom"><x-ui.input wire:model="freezeFrom" id="freezeFrom" type="date" /></x-ui.field>
                <x-ui.field label="To" name="freezeTo" for="freezeTo"><x-ui.input wire:model="freezeTo" id="freezeTo" type="date" /></x-ui.field>
            </div>
            <x-ui.field label="Reason" name="freezeReason" for="freezeReason"><x-ui.input wire:model="freezeReason" id="freezeReason" placeholder="Travel, illness…" /></x-ui.field>
            <x-ui.error :messages="$errors->get('freeze')" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'freeze-form' })">Cancel</x-ui.button><x-ui.button type="submit">Freeze</x-ui.button></div>
        </form>
    </x-ui.modal>
</div>
