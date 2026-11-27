<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Order {{ $sale?->invoiceNumber() }} · {{ $shop->invoiceDisplayName() }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body data-app class="bg-page font-sans text-ink antialiased">
    <main class="mx-auto max-w-md px-4 py-8 sm:py-12" data-order-payment>
        <p class="text-sm font-semibold uppercase tracking-[0.08em] text-ink-faint">{{ $shop->invoiceDisplayName() }}</p>
        <h1 class="mt-1 text-display-sm font-extrabold text-ink">Order {{ $sale?->invoiceNumber() }}</h1>

        @foreach (['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger'] as $key => $variant)
            @if (session($key))
                <div @class(['mt-5 rounded-xl border px-4 py-3 text-sm font-medium', 'border-success/20 bg-success-soft text-success' => $variant === 'success', 'border-warning/20 bg-warning-soft text-warning' => $variant === 'warning', 'border-danger/20 bg-danger-soft text-danger' => $variant === 'danger']) role="status" data-payment-result="{{ $key }}">{{ session($key) }}</div>
            @endif
        @endforeach

        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-medium text-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="mt-6 rounded-2xl border border-line bg-surface p-5 shadow-soft sm:p-6">
            @if ($sale)
                <ul class="divide-y divide-line text-sm">
                    @foreach ($sale->items as $item)
                        <li class="flex justify-between gap-3 py-2"><span class="text-ink">{{ $item->product_name }} × {{ \App\Support\Quantity::plain($item->quantity) }}</span><span class="tabular-nums text-ink-muted">Rs {{ number_format($item->line_total, 2) }}</span></li>
                    @endforeach
                    @if ((float) $sale->delivery_charge > 0)
                        <li class="flex justify-between gap-3 py-2"><span class="text-ink">Delivery</span><span class="tabular-nums text-ink-muted">Rs {{ number_format($sale->delivery_charge, 2) }}</span></li>
                    @endif
                </ul>
            @endif

            <p class="mt-5 text-sm text-ink-faint">To pay now</p>
            <p class="mt-1 text-display font-extrabold tabular-nums {{ $amount > 0 ? 'text-ink' : 'text-success' }}" data-order-amount>Rs {{ number_format($amount, 2) }}</p>

            @if ($link->cancelled_at)
                <p class="mt-3 text-sm text-ink-muted">This link has been replaced. Ask the shop for the new one.</p>
            @elseif ($amount < \App\Actions\CustomerPayments::MIN_AMOUNT)
                <p class="mt-1 text-sm font-semibold text-success">Nothing to pay. Thank you!</p>
            @elseif ($gateway)
                <form method="POST" action="{{ route('order-payment.pay', $link->token) }}" class="mt-5" data-pay-form>
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-full bg-sale px-6 py-3 text-base font-semibold text-sale-ink shadow-soft hover:bg-sale-hover">Pay with JazzCash</button>
                </form>
                <p class="mt-3 text-xs text-ink-faint">You'll pay on JazzCash's own page, by JazzCash wallet or card.</p>
            @else
                <p class="mt-3 text-sm text-ink-muted">Online payment isn't available right now. Please pay the shop directly.</p>
            @endif
        </section>
    </main>
</body>
</html>
