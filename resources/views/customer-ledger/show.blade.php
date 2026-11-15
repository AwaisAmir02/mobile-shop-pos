<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $customer->name }} · {{ $shop->invoiceDisplayName() }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body data-app class="bg-page font-sans text-ink antialiased">
    <main class="mx-auto max-w-2xl px-4 py-8 sm:py-12" data-customer-ledger>
        <p class="text-sm font-semibold uppercase tracking-[0.08em] text-ink-faint">{{ $shop->invoiceDisplayName() }}</p>
        <h1 class="mt-1 text-display-sm font-extrabold text-ink">{{ $customer->name }}’s account</h1>
        <p class="mt-1 text-sm text-ink-faint">Your own record with this shop, updated as the shop enters it. Only people with this link can see it.</p>

        @foreach (['paid' => 'success', 'pending' => 'warning', 'failed' => 'danger'] as $key => $variant)
            @if (session($key))
                <div @class(['mt-5 rounded-xl border px-4 py-3 text-sm font-medium', 'border-success/20 bg-success-soft text-success' => $variant === 'success', 'border-warning/20 bg-warning-soft text-warning' => $variant === 'warning', 'border-danger/20 bg-danger-soft text-danger' => $variant === 'danger']) role="status" data-payment-result="{{ $key }}">{{ session($key) }}</div>
            @endif
        @endforeach

        <section class="mt-6 rounded-2xl border border-line bg-surface p-5 shadow-soft sm:p-6">
            <p class="text-sm text-ink-faint">You owe</p>
            <p class="mt-1 text-display font-extrabold tabular-nums {{ $total > 0 ? 'text-ink' : 'text-success' }}" data-ledger-total>Rs {{ number_format(max(0, $total), 2) }}</p>
            @if ($total <= 0)
                <p class="mt-1 text-sm font-semibold text-success">All clear. Nothing is owed.</p>
            @endif

            @if ($total >= \App\Actions\CustomerPayments::MIN_AMOUNT && $gateway)
                <form method="POST" action="{{ route('customer-ledger.pay', $token) }}" class="mt-5 flex flex-wrap items-end gap-3" data-pay-form>
                    @csrf
                    <label class="block min-w-0 flex-1 text-sm font-medium text-ink-muted">
                        Amount to pay
                        <input type="number" name="amount" min="{{ \App\Actions\CustomerPayments::MIN_AMOUNT }}" max="{{ $total }}" step="1" value="{{ old('amount', (int) ceil($total)) > $total ? $total : old('amount', $total) }}" class="mt-1.5 block w-full rounded-lg border-line-strong bg-surface py-2.5 text-base text-ink focus:border-link focus:ring-link" required>
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center rounded-full bg-sale px-6 py-3 text-base font-semibold text-sale-ink shadow-soft hover:bg-sale-hover">Pay with JazzCash</button>
                    @error('amount')<p class="basis-full text-sm text-danger">{{ $message }}</p>@enderror
                    <p class="basis-full text-xs text-ink-faint">Pay from your JazzCash wallet or a debit/credit card. The money goes straight to {{ $shop->invoiceDisplayName() }}.</p>
                </form>
            @endif
        </section>

        @if ($owed['udhaar'] > 0 || $owed['items']->isNotEmpty())
            <section class="mt-6 rounded-2xl border border-line bg-surface shadow-soft">
                <h2 class="border-b border-line px-5 py-3.5 text-base font-bold text-ink">What makes up the total</h2>
                <ul class="divide-y divide-line text-sm">
                    @if ($owed['udhaar'] > 0)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <span class="text-ink">Udhaar (cash loan)</span>
                            <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($owed['udhaar'], 2) }}</span>
                        </li>
                    @endif
                    @foreach ($owed['items'] as $item)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <span class="min-w-0">
                                <span class="block truncate text-ink">{{ $item['label'] }}</span>
                                <span class="block text-xs text-ink-faint">{{ $item['reference'] }} · {{ $item['date']->format('d M Y') }}@isset($item['installments']) · {{ $item['installments']['summary'] }}@endisset</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums text-ink">Rs {{ number_format($item['amountDue'], 2) }}</span>
                        </li>
                    @endforeach
                    @if ($owed['udhaar'] < 0)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <span class="text-ink">Advance you have with the shop</span>
                            <span class="font-semibold tabular-nums text-success">− Rs {{ number_format(abs($owed['udhaar']), 2) }}</span>
                        </li>
                    @endif
                </ul>
            </section>
        @endif

        <section class="mt-6 rounded-2xl border border-line bg-surface shadow-soft">
            <h2 class="border-b border-line px-5 py-3.5 text-base font-bold text-ink">History</h2>
            @if ($history->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-faint">Nothing on credit yet.</p>
            @else
                <table class="w-full text-sm" data-ledger-history>
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-ink-faint">
                        <tr><th class="px-5 py-2.5">Date</th><th class="py-2.5">What</th><th class="py-2.5 text-right">Amount</th><th class="px-5 py-2.5 text-right">Balance</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($history as $row)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-2.5 text-ink-muted">{{ $row['date']->format('d M Y') }}</td>
                                <td class="py-2.5 text-ink">{{ $row['description'] }}@if ($row['detail'])<span class="block text-xs text-ink-faint">{{ \Illuminate\Support\Str::limit($row['detail'], 40) }}</span>@endif</td>
                                <td class="whitespace-nowrap py-2.5 text-right tabular-nums {{ $row['credit'] > 0 ? 'text-success' : 'text-ink' }}">{{ $row['credit'] > 0 ? '− Rs '.number_format($row['credit'], 2) : 'Rs '.number_format($row['debit'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right font-semibold tabular-nums text-ink">Rs {{ number_format($row['balance'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <p class="mt-8 text-center text-xs text-ink-faint">Something wrong? Contact {{ $shop->invoiceDisplayName() }}{{ $shop->phone ? ' on '.$shop->phone : '' }}. · {{ config('branding.powered_by') }}</p>
    </main>
</body>
</html>
