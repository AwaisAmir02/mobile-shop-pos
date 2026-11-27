@props([
    'vouchersOn' => false,
    'vouchers' => [],
    'deposits',
])

{{-- Gift vouchers and deposits paying part of the bill (SellsServices). --}}
@if ($vouchersOn || $deposits->isNotEmpty())
    <div class="space-y-3 rounded-xl border border-line p-3" data-prepaid-panel>
        @if ($vouchersOn)
            <div>
                <p class="text-sm font-semibold text-ink">Gift voucher</p>
                <div class="mt-1 flex gap-2">
                    <x-ui.input wire:model="voucherCode" wire:keydown.enter.prevent="applyVoucher" placeholder="Voucher code" class="uppercase" aria-label="Voucher code" autocomplete="off" />
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="applyVoucher" data-apply-voucher>Use</x-ui.button>
                </div>
                <x-ui.error :messages="$errors->get('voucherCode')" />
                @foreach ($vouchers as $i => $voucher)
                    <div class="mt-1 flex items-center justify-between text-sm" wire:key="voucher-{{ $voucher['id'] }}">
                        <span class="text-ink">{{ $voucher['code'] }} · Rs {{ number_format($voucher['left'], 2) }} left</span>
                        <button type="button" wire:click="removeVoucher({{ $i }})" class="text-xs font-semibold text-link hover:underline">Remove</button>
                    </div>
                @endforeach
            </div>
        @endif
        @foreach ($deposits as $deposit)
            <x-ui.checkbox wire:model.live="useDeposits.{{ $deposit->id }}" wire:key="deposit-{{ $deposit->id }}">
                <span class="font-semibold text-ink">Use deposit {{ $deposit->number() }}</span> · Rs {{ number_format($deposit->remaining(), 2) }} held
            </x-ui.checkbox>
        @endforeach
        <x-ui.error :messages="$errors->get('prepaid')" />
    </div>
@endif
