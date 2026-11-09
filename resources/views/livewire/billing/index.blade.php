<?php

use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\ShopAddonStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\PaymentGateways;
use App\Services\Billing\PlanChangeService;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Plan & Usage')] class extends Component
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

    public function switchPlan(int $planId, PlanChangeService $changes): void
    {
        abort_unless(Auth::user()->is_owner, 403);

        try {
            $changes->request(Auth::user()->shop, Plan::findOrFail($planId), Auth::id());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess('Plan change requested — pay the invoice above and your new plan starts the day we confirm it.');
    }

    public function with(PaymentGateways $gateways, Entitlements $entitlements, PlanChangeService $changes): array
    {
        $shop = Auth::user()->shop()->with('plan')->first();
        $payment = $this->openPayment()?->load('invoice.plan');
        $governed = $entitlements->governed($shop);

        // Packs for a limit the shop already has unlimited would add nothing.
        $catalogue = Addon::active()->ordered()->get()
            ->reject(fn (Addon $addon) => $addon->kind === AddonKind::FeatureUnlock && $entitlements->planIncludesAll($shop, $addon->feature_keys))
            ->reject(fn (Addon $addon) => $addon->kind === AddonKind::LimitPack && $addon->limit_key && $entitlements->limit($shop, $addon->limit_key) === null)
            ->values();

        $plans = $governed
            ? Plan::active()->ordered()->get()->when($shop->plan && ! $shop->plan->is_active, fn ($plans) => $plans->prepend($shop->plan))
            : collect();

        return [
            'shop' => $shop,
            'payment' => $payment,
            'step' => $payment ? $gateways->for($payment->gateway)->nextStep($payment) : null,
            'history' => SubscriptionPayment::where('shop_id', $shop->id)->with('invoice')->latest('id')->limit(10)->get(),
            'meters' => $entitlements->meters($shop, fresh: true, everyLimit: true),
            'governed' => $governed,
            'features' => $entitlements->features($shop),
            'catalogue' => $catalogue,
            'packs' => $catalogue->where('kind', AddonKind::LimitPack)->groupBy(fn (Addon $addon) => $addon->limit_key->value)->map->first(),
            'myAddons' => $shop->addons()->whereIn('status', [ShopAddonStatus::Pending->value, ShopAddonStatus::Active->value])->latest('id')->get(),
            'plans' => $plans,
            'blockers' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->id === $shop->plan_id ? null : $changes->blockerMessage($shop, $plan)]),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Plan &amp; Usage</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($payment)
                <x-ui.card title="Complete your payment" :description="($payment->shopAddon ? 'For the '.$payment->shopAddon->name.' add-on. ' : '').($payment->invoice->plan ? 'For switching to '.$payment->invoice->plan->name.'. ' : '').'Transfer the amount below, then enter the transaction reference so we can confirm it.'">
                    @if ($payment->status === SubscriptionPaymentStatus::Rejected)
                        <div class="mb-5 rounded-lg border border-danger/20 bg-danger-soft p-4 text-sm text-danger">
                            <p class="font-semibold">We couldn’t confirm your last payment.</p>
                            <p class="mt-1">{{ $payment->rejection_reason }}</p>
                            <p class="mt-1">Please check the details and submit again.</p>
                        </div>
                    @elseif ($payment->isAwaitingReview())
                        <div class="mb-5 rounded-lg border border-success/20 bg-success-soft p-4 text-sm text-success">
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
                        <div class="mt-5 rounded-lg bg-surface-sunken p-4">
                            <p class="text-sm font-semibold text-ink">Bank transfer details</p>
                            <p class="mt-2 whitespace-pre-line font-mono text-sm text-ink-muted">{{ $step['instructions'] }}</p>
                            <p class="mt-3 text-xs text-ink-faint">Use invoice number <span class="font-semibold">{{ $payment->invoice->invoice_number }}</span> as the transfer note if your bank allows it.</p>
                        </div>
                    @endif

                    <div class="mt-4">
                        <a href="{{ route('billing.invoices.pdf', $payment->invoice) }}" class="text-sm font-semibold text-link hover:underline">Download invoice (PDF)</a>
                    </div>

                    <form wire:submit="submitProof" class="mt-6 space-y-4 border-t border-line pt-6">
                        <x-ui.field label="Transaction reference / TID" name="reference" for="reference" help="Shown in your banking app after the transfer">
                            <x-ui.input wire:model="reference" id="reference" maxlength="100" />
                        </x-ui.field>
                        <x-ui.field label="Screenshot of the transfer" name="proof" for="proof" help="JPG, PNG or WebP, up to 4 MB">
                            <input type="file" wire:model="proof" id="proof" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-ink-muted file:mr-3 file:rounded-md file:border-0 file:bg-primary-soft file:px-3 file:py-2 file:text-sm file:font-semibold file:text-link hover:file:bg-primary-soft">
                        </x-ui.field>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm font-medium text-ink-muted hover:text-ink">Skip for now — go to my dashboard</a>
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitProof,proof">{{ $payment->isAwaitingReview() ? 'Update payment details' : 'Submit payment details' }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-success/20 bg-success-soft px-4 py-3 text-sm">
                    <p class="flex items-center gap-2">
                        <svg class="h-5 w-5 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><span class="font-semibold text-ink">You’re all set.</span> <span class="text-ink-muted">There’s no payment due right now.</span></span>
                    </p>
                    <a href="{{ route('dashboard') }}" wire:navigate class="text-sm font-semibold text-link hover:underline">Go to dashboard</a>
                </div>
            @endif

            @if ($meters !== [])
                <x-ui.card title="Usage" description="Monthly counts reset on the 1st (Pakistan time).">
                    <x-billing.usage-meters :meters="$meters" remaining :packs="$packs" :can-buy="$payment === null" />
                    <p class="mt-4 text-xs text-ink-faint">Out-of-stock products you no longer sell can be archived from Products so they stop counting.</p>
                    @if ($payment && $packs->isNotEmpty())
                        <p class="mt-1 text-xs text-ink-faint">Finish your pending payment before adding more.</p>
                    @endif
                </x-ui.card>
            @endif

            @if ($governed && $plans->isNotEmpty())
                <x-ui.card id="plans" title="Change plan" description="The new plan starts the day we confirm its payment, for its full price — no part-period charges.">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($plans as $plan)
                            @php($isCurrent = $plan->id === $shop->plan_id)
                            <div @class(['flex flex-col rounded-lg border p-4', 'border-link ring-1 ring-link' => $isCurrent, 'border-line' => ! $isCurrent]) wire:key="plan-{{ $plan->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold text-ink">{{ $plan->name }}</p>
                                    @if ($isCurrent)
                                        <x-ui.badge variant="brand">Current plan</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-ink-muted"><span class="text-lg font-semibold text-ink">{{ $plan->priceLabel() }}</span> / {{ $plan->intervalLabel() }}</p>
                                @if ($plan->tagline)
                                    <p class="mt-1 text-xs text-ink-faint">{{ $plan->tagline }}</p>
                                @endif

                                <ul class="mt-3 space-y-1 text-sm">
                                    @foreach (LimitKey::cases() as $key)
                                        @php($planLimit = ($plan->limits ?? [])[$key->value] ?? null)
                                        <li class="flex justify-between gap-2">
                                            <span class="text-ink-muted">{{ $key->label() }}</span>
                                            <span class="font-medium tabular-nums text-ink">{{ $planLimit === null ? 'Unlimited' : number_format((int) $planLimit) }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <ul class="mt-3 flex-1 space-y-1 border-t border-line pt-3 text-sm">
                                    @foreach (FeatureKey::planCases() as $feature)
                                        @php($inPlan = (bool) (($plan->feature_flags ?? [])[$feature->value] ?? false))
                                        <li @class(['flex items-center gap-2', 'text-ink-muted' => $inPlan, 'text-ink-faint' => ! $inPlan])>
                                            <span aria-hidden="true" @class(['font-semibold', 'text-success' => $inPlan])>{{ $inPlan ? '✓' : '–' }}</span>
                                            <span>{{ $feature->label() }}<span class="sr-only">: {{ $inPlan ? 'included' : 'not included' }}</span></span>
                                        </li>
                                    @endforeach
                                </ul>

                                <div class="pt-4">
                                    @if ($isCurrent)
                                        <p class="text-sm text-ink-faint">You’re on this plan.</p>
                                    @elseif ($blockers[$plan->id])
                                        <p class="mb-2 rounded-md bg-warning-soft p-2 text-xs text-warning">{{ $blockers[$plan->id] }}</p>
                                        <x-ui.button variant="secondary" class="w-full" disabled>Switch to this plan</x-ui.button>
                                    @else
                                        <x-ui.button class="w-full" wire:click="switchPlan({{ $plan->id }})" confirm="Switch to {{ $plan->name }} for {{ $plan->priceLabel() }} / {{ $plan->intervalLabel() }}? We'll create an invoice for you to pay by bank transfer. Your plan changes the day we confirm it." :disabled="$payment !== null">Switch to this plan</x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($payment)
                        <p class="mt-3 text-xs text-ink-faint">Finish your pending payment before changing your plan.</p>
                    @endif
                </x-ui.card>
            @elseif (! $governed)
                <x-ui.card title="Change plan">
                    <p class="text-sm text-ink-muted">Your shop’s plan is set up by HisabOKitab. Contact us to change it.</p>
                </x-ui.card>
            @endif

            @if ($history->isNotEmpty())
                <x-ui.card title="Payment history">
                    <x-ui.table stack :headers="['Invoice', 'Amount', 'Reference', 'Status']">
                        @foreach ($history as $row)
                            <x-ui.table-row wire:key="payment-{{ $row->id }}">
                                <x-ui.table-cell primary class="font-medium text-ink">{{ $row->invoice?->invoice_number }}</x-ui.table-cell>
                                <x-ui.table-cell label="Amount">Rs {{ number_format((float) $row->amount, 2) }}</x-ui.table-cell>
                                <x-ui.table-cell label="Reference" class="font-mono text-xs">{{ $row->reference ?? '—' }}</x-ui.table-cell>
                                <x-ui.table-cell label="Status"><x-ui.badge :variant="$row->status->badge()">{{ $row->status->label() }}</x-ui.badge></x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Your plan">
                @if ($shop->plan)
                    <p class="text-lg font-semibold text-ink">{{ $shop->plan->name }}</p>
                    <p class="text-sm text-ink-faint">{{ $shop->plan->priceLabel() }} / {{ $shop->plan->intervalLabel() }}</p>
                @else
                    <p class="text-sm text-ink-faint">No plan assigned.</p>
                @endif
                <div class="mt-4">
                    <x-ui.badge :variant="$shop->isSubscriptionActive() ? 'success' : 'warning'">{{ $shop->isSubscriptionActive() ? 'Active' : 'Awaiting payment' }}</x-ui.badge>
                </div>

                {{-- The full list is long, so the first few show and the rest open on
                     request. Every row stays in the page. --}}
                <div class="mt-5 border-t border-line pt-4" x-data="{ showAll: false }">
                    <p class="text-sm font-semibold text-ink">What’s included</p>
                    <ul class="mt-3 space-y-2.5">
                        @foreach ($features as $feature)
                            <li class="flex items-start gap-2 text-sm" wire:key="feature-{{ $feature['key']->value }}" {!! $loop->index >= 8 ? 'x-show="showAll" x-cloak' : '' !!}>
                                <span aria-hidden="true" @class(['mt-0.5 font-semibold', 'text-success' => $feature['on'], 'text-ink-faint' => ! $feature['on']])>{{ $feature['on'] ? '✓' : '–' }}</span>
                                <span class="min-w-0">
                                    <span @class(['font-medium', 'text-ink' => $feature['on'], 'text-ink-faint' => ! $feature['on']])>{{ $feature['label'] }}</span>
                                    <span class="block text-xs text-ink-faint">{{ match ($feature['source']) {
                                        'hisabokitab' => ($feature['on'] ? 'On' : 'Off').' — set by HisabOKitab',
                                        'addon' => 'Add-on'.($feature['until'] ? ' until '.$feature['until']->format('d M Y') : ''),
                                        default => $feature['on'] ? 'Included in your plan' : 'Not in your plan',
                                    } }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if (count($features) > 8)
                        <button type="button" x-on:click="showAll = ! showAll" :aria-expanded="showAll.toString()" class="mt-3 text-sm font-semibold text-link hover:underline">
                            <span x-show="! showAll">Show all {{ count($features) }}</span>
                            <span x-show="showAll" x-cloak>Show fewer</span>
                        </button>
                    @endif
                </div>
            </x-ui.card>

            @if ($governed && ($catalogue->isNotEmpty() || $myAddons->isNotEmpty()))
                <x-ui.card id="addons" title="Add-ons" description="Need a bit more? Add it to your plan.">
                    @if ($myAddons->isNotEmpty())
                        <ul class="mb-4 space-y-2">
                            @foreach ($myAddons as $mine)
                                <li class="flex items-start justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 text-sm" wire:key="mine-{{ $mine->id }}">
                                    <span>
                                        <span class="font-medium text-ink">{{ $mine->name }}</span>
                                        @if ($mine->ends_at)
                                            <span class="block text-xs text-ink-faint">Until {{ $mine->ends_at->format('d M Y') }}</span>
                                        @endif
                                    </span>
                                    <x-ui.badge :variant="$mine->status->badge()">{{ $mine->status->label() }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <ul class="space-y-3">
                        @foreach ($catalogue as $addon)
                            <li class="rounded-lg border border-line p-3" wire:key="addon-{{ $addon->id }}">
                                <p class="font-medium text-ink">{{ $addon->name }}</p>
                                <p class="text-xs text-ink-faint">{{ $addon->description ?? $addon->grants() }}</p>
                                <div class="mt-2 flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-ink">{{ $addon->priceLabel() }}</span>
                                    <x-ui.button size="sm" wire:click="buyAddon({{ $addon->id }})" confirm="Buy {{ $addon->name }} for {{ $addon->priceLabel() }}? We'll create an invoice for you to pay by bank transfer." :disabled="$payment !== null">Buy</x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($payment)
                        <p class="mt-3 text-xs text-ink-faint">Finish your pending payment before buying another add-on.</p>
                    @endif
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
