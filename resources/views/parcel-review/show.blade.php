<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Your order from {{ $shop->invoiceDisplayName() }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body data-app class="bg-page font-sans text-ink antialiased">
    <main class="mx-auto max-w-md px-4 py-8 sm:py-12" data-parcel-review>
        <p class="text-sm font-semibold uppercase tracking-[0.08em] text-ink-faint">{{ $shop->invoiceDisplayName() }}</p>
        <h1 class="mt-1 text-display-sm font-extrabold text-ink">How was your order?</h1>
        <p class="mt-1 text-sm text-ink-faint">{{ $items->pluck('product_name')->unique()->take(3)->implode(', ') }}</p>

        @if (session('thanked'))
            <div class="mt-5 rounded-xl border border-success/20 bg-success-soft px-4 py-3 text-sm font-medium text-success" role="status" data-review-thanks>Thank you! Your feedback reached {{ $shop->invoiceDisplayName() }}.</div>
        @endif

        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-medium text-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        @if (! $delivered)
            <p class="mt-6 text-sm text-ink-muted">You can leave feedback once the order is delivered.</p>
        @else
            <form method="POST" action="{{ route('parcel-review.store', $shipment->review_token) }}" class="mt-6 space-y-5 rounded-2xl border border-line bg-surface p-5 shadow-soft sm:p-6" data-review-form>
                @csrf
                <fieldset>
                    <legend class="text-sm font-medium text-ink-muted">Your rating</legend>
                    <div class="mt-2 flex gap-2">
                        @foreach ([5 => 'Excellent', 4 => 'Good', 3 => 'Okay', 2 => 'Poor', 1 => 'Bad'] as $stars => $label)
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="rating" value="{{ $stars }}" class="peer sr-only" @checked((int) old('rating', $review?->rating) === $stars) required>
                                <span class="block rounded-xl border border-line px-2 py-2 text-center text-sm peer-checked:border-primary peer-checked:bg-primary-soft">{{ str_repeat('★', $stars) }}<span class="block text-xs text-ink-faint">{{ $label }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="block text-sm font-medium text-ink-muted">
                    Anything to add? (optional)
                    <textarea name="comment" rows="3" maxlength="1000" class="mt-1.5 block w-full rounded-lg border-line-strong bg-surface text-base text-ink focus:border-link focus:ring-link">{{ old('comment', $review?->comment) }}</textarea>
                </label>
                <label class="block text-sm font-medium text-ink-muted">
                    Your name, as the shop may show it (optional)
                    <input type="text" name="display_name" maxlength="100" value="{{ old('display_name', $review?->display_name) }}" class="mt-1.5 block w-full rounded-lg border-line-strong bg-surface text-base text-ink focus:border-link focus:ring-link">
                </label>
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent', $review?->publish_consent)) class="mt-0.5 rounded border-line-strong text-primary focus:ring-link">
                    <span>{{ $shop->invoiceDisplayName() }} may share my review</span>
                </label>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-full bg-sale px-6 py-3 text-base font-semibold text-sale-ink shadow-soft hover:bg-sale-hover">{{ $review ? 'Update my review' : 'Send my review' }}</button>
            </form>
        @endif
    </main>
</body>
</html>
