<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Opening JazzCash…</title>
    @vite(['resources/css/app.css'])
</head>
<body data-app class="bg-page font-sans text-ink antialiased">
    {{-- JazzCash's hosted checkout takes a signed form post, sent on by the browser. --}}
    <form id="jazzcash" method="POST" action="{{ $action }}" class="mx-auto max-w-md px-4 py-16 text-center" data-jazzcash-form>
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <p class="text-base font-semibold text-ink">Taking you to JazzCash…</p>
        <button type="submit" class="mt-4 rounded-full bg-sale px-6 py-3 font-semibold text-sale-ink">Continue to JazzCash</button>
    </form>
    <script>document.getElementById('jazzcash').submit();</script>
</body>
</html>
