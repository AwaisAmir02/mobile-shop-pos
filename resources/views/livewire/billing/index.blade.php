<?php

use App\Enums\AddonKind;
use App\Enums\ShopAddonStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Addon;
use App\Models\SubscriptionPayment;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\PaymentGateways;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Plan & Billing')] class extends Component
{
    use Toasts, WithFileUploads;

    public string $reference = '';
    public $proof = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->is_owner, 403);
    }

    protected function openPayment(): ?SubscriptionPayment
    {
        return SubscriptionPayment::openFor(Auth::user()->shop_id);
    }

    public function submitProof(SubscriptionPaymentService $payments): void
    {
        abort_unless(Auth::user()->is_owner, 403);

        $payment = $this->openPayment() ?? abort(404);

        $this->validate([
            'reference' => ['required', 'string', 'min:4', 'max:100'],
            'proof' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('billing.proof_max_kb')],
        ], [], ['reference' => 'transaction reference', 'proof' => 'screenshot']);

        try {
            $payments->submitProof($payment, $this->reference, $this->proof);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->reset(['reference', 'proof']);
        $this->toastSuccess('Thanks! We’ll confirm your payment shortly.');
    }

    public function buyAddon(int $addonId, AddonService $addons): void
    {
        abort_unless(Auth::user()->is_owner, 403);

        try {
            $addons->purchase(Auth::user()->shop, Addon::active()->findOrFail($addonId), Auth::id());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess('Add-on reserved — pay the invoice below and it switches on once we confirm.');
    }

    public function with(PaymentGateways $gateways): array
    {
        $shop = Auth::user()->shop()->with('plan')->first();
        $payment = $this->openPayment()?->load('invoice');

        return [
            'shop' => $shop,
            'payment' => $payment,
            'step' => $payment ? $gateways->for($payment->gateway)->nextStep($payment) : null,
            'history' => SubscriptionPayment::where('shop_id', $shop->id)->with('invoice')->latest('id')->limit(10)->get(),
            'meters' => app(Entitlements::class)->meters($shop, fresh: true),
            'governed' => app(Entitlements::class)->governed($shop),
            'catalogue' => Addon::active()->ordered()->get()->reject(fn (Addon $addon) => $addon->kind === AddonKind::FeatureUnlock && app(Entitlements::class)->planFeature($shop, $addon->feature_key) === true)->values(),
            'myAddons' => $shop->addons()->whereIn('status', [ShopAddonStatus::Pending->value, ShopAddonStatus::Active->value])->latest('id')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Plan &amp; Billing</h1>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($payment)
                <x-ui.card title="Complete your payment" :description="($payment->shopAddon ? 'For the '.$payment->shopAddon->name.' add-on. ' : '').'Transfer the amount below, then enter the transaction reference so we can confirm it.'">
                    @if ($payment->status === SubscriptionPaymentStatus::Rejected)
                        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                            <p class="font-semibold">We couldn’t confirm your last payment.</p>
                            <p class="mt-1">{{ $payment->rejection_reason }}</p>
                            <p class="mt-1">Please check the details and submit again.</p>
                        </div>
                    @elseif ($payment->isAwaitingReview())
                        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                            <p class="font-semibold">Payment details received — we’re checking them.</p>
                            <p class="mt-1">Reference <span class="font-mono">{{ $payment->reference }}</span>, sent {{ $payment->submitted_at->diffForHumans() }}. You can keep using your shop in the meantime.</p>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-ui.stat label="Amount to pay" value="Rs {{ number_format((float) $payment->amount, 2) }}" />
                        <x-ui.stat label="Invoice" :value="$payment->invoice->invoice_number" />
                        <x-ui.stat label="Covers" value="{{ $payment->invoice->period_start->format('d M Y') }} – {{ $payment->invoice->period_end->format('d M Y') }}" />
                    </div>

                    @if (($step['type'] ?? null) === 'instructions')
                        <div class="mt-5 rounded-lg bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900">Bank transfer details</p>
                            <p class="mt-2 whitespace-pre-line font-mono text-sm text-slate-700">{{ $step['instructions'] }}</p>
                            <p class="mt-3 text-xs text-slate-500">Use invoice number <span class="font-semibold">{{ $payment->invoice->invoice_number }}</span> as the transfer note if your bank allows it.</p>
                        </div>
                    @endif

                    <div class="mt-4">
                        <a href="{{ route('billing.invoices.pdf', $payment->invoice) }}" class="text-sm font-semibold text-brand-700 hover:underline">Download invoice (PDF)</a>
                    </div>

                    <form wire:submit="submitProof" class="mt-6 space-y-4 border-t border-slate-100 pt-6">
                        <x-ui.field label="Transaction reference / TID" name="reference" for="reference" help="Shown in your banking app after the transfer">
                            <x-ui.input wire:model="reference" id="reference" maxlength="100" />
                        </x-ui.field>
                        <x-ui.field label="Screenshot of the transfer" name="proof" for="proof" help="Optional — JPG, PNG or WebP, up to 4 MB">
                            <input type="file" wire:model="proof" id="proof" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        </x-ui.field>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm font-medium text-slate-600 hover:text-slate-900">Skip for now — go to my dashboard</a>
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitProof,proof">{{ $payment->isAwaitingReview() ? 'Update payment details' : 'Submit payment details' }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card title="You’re all set">
                    <p class="text-sm text-slate-600">There’s no payment due right now.</p>
                    <a href="{{ route('dashboard') }}" wire:navigate class="mt-4 inline-block text-sm font-semibold text-brand-700 hover:underline">Go to dashboard</a>
                </x-ui.card>
            @endif

            @if ($history->isNotEmpty())
                <x-ui.card title="Payment history">
                    <x-ui.table :headers="['Invoice', 'Amount', 'Reference', 'Status']">
                        @foreach ($history as $row)
                            <x-ui.table-row wire:key="payment-{{ $row->id }}">
                                <x-ui.table-cell class="font-medium text-slate-900">{{ $row->invoice?->invoice_number }}</x-ui.table-cell>
                                <x-ui.table-cell>Rs {{ number_format((float) $row->amount, 2) }}</x-ui.table-cell>
                                <x-ui.table-cell class="font-mono text-xs">{{ $row->reference ?? '—' }}</x-ui.table-cell>
                                <x-ui.table-cell><x-ui.badge :variant="$row->status->badge()">{{ $row->status->label() }}</x-ui.badge></x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Your plan">
                @if ($shop->plan)
                    <p class="text-lg font-semibold text-slate-900">{{ $shop->plan->name }}</p>
                    <p class="text-sm text-slate-500">{{ $shop->plan->priceLabel() }} / {{ $shop->plan->intervalLabel() }}</p>
                @else
                    <p class="text-sm text-slate-500">No plan assigned.</p>
                @endif
                <div class="mt-4">
                    <x-ui.badge :variant="$shop->isSubscriptionActive() ? 'success' : 'warning'">{{ $shop->isSubscriptionActive() ? 'Active' : 'Awaiting payment' }}</x-ui.badge>
                </div>
            </x-ui.card>

            @if ($governed && ($catalogue->isNotEmpty() || $myAddons->isNotEmpty()))
                <x-ui.card title="Add-ons" description="Need a bit more? Add it to your plan.">
                    @if ($myAddons->isNotEmpty())
                        <ul class="mb-4 space-y-2">
                            @foreach ($myAddons as $mine)
                                <li class="flex items-start justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm" wire:key="mine-{{ $mine->id }}">
                                    <span>
                                        <span class="font-medium text-slate-900">{{ $mine->name }}</span>
                                        @if ($mine->ends_at)
                                            <span class="block text-xs text-slate-500">Until {{ $mine->ends_at->format('d M Y') }}</span>
                                        @endif
                                    </span>
                                    <x-ui.badge :variant="$mine->status->badge()">{{ $mine->status->label() }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <ul class="space-y-3">
                        @foreach ($catalogue as $addon)
                            <li class="rounded-lg border border-slate-200 p-3" wire:key="addon-{{ $addon->id }}">
                                <p class="font-medium text-slate-900">{{ $addon->name }}</p>
                                <p class="text-xs text-slate-500">{{ $addon->description ?? $addon->grants() }}</p>
                                <div class="mt-2 flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-slate-900">{{ $addon->priceLabel() }}</span>
                                    <x-ui.button size="sm" wire:click="buyAddon({{ $addon->id }})" wire:confirm="Buy {{ $addon->name }} for {{ $addon->priceLabel() }}? We'll create an invoice for you to pay by bank transfer." :disabled="$payment !== null">Buy</x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($payment)
                        <p class="mt-3 text-xs text-slate-500">Finish your pending payment before buying another add-on.</p>
                    @endif
                </x-ui.card>
            @endif

            @if ($meters !== [])
                <x-ui.card title="Usage" description="Monthly counts reset on the 1st (Pakistan time).">
                    <x-billing.usage-meters :meters="$meters" />
                    <p class="mt-4 text-xs text-slate-500">Out-of-stock products you no longer sell can be archived from Products so they stop counting.</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
