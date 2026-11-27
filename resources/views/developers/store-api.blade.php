<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <title>Online Store API · HisabOKitab developers</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css'])
    {{-- The contract itself is docs/channel-api-v1.md, rendered here so it can't drift from the copy in the code. --}}
    <style>
        .api-doc h1 { font-size: 1.875rem; font-weight: 800; margin: 0 0 1rem; }
        .api-doc h2 { font-size: 1.375rem; font-weight: 700; margin: 2.5rem 0 .75rem; padding-top: 1rem; border-top: 1px solid rgb(0 0 0 / .08); }
        .api-doc h3 { font-size: 1.125rem; font-weight: 700; margin: 1.75rem 0 .5rem; }
        .api-doc p, .api-doc ul, .api-doc ol { margin: .75rem 0; line-height: 1.65; }
        .api-doc ul { list-style: disc; padding-left: 1.5rem; }
        .api-doc ol { list-style: decimal; padding-left: 1.5rem; }
        .api-doc li { margin: .25rem 0; }
        .api-doc code { font-size: .875em; background: rgb(0 0 0 / .05); padding: .1rem .3rem; border-radius: .25rem; }
        .api-doc pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: .75rem; overflow-x: auto; margin: 1rem 0; font-size: .8125rem; line-height: 1.55; }
        .api-doc pre code { background: none; padding: 0; color: inherit; }
        .api-doc table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .875rem; }
        .api-doc th, .api-doc td { border: 1px solid rgb(0 0 0 / .1); padding: .5rem .625rem; text-align: left; vertical-align: top; }
        .api-doc th { background: rgb(0 0 0 / .03); }
        .api-doc blockquote { border-left: 4px solid rgb(0 0 0 / .15); padding-left: 1rem; color: rgb(0 0 0 / .65); margin: 1rem 0; }
        .api-doc a { text-decoration: underline; }
    </style>
</head>
<body data-app class="bg-page font-sans text-ink antialiased">
    <main class="api-doc mx-auto max-w-4xl px-4 py-10" data-developer-docs>
        {!! $html !!}
    </main>
</body>
</html>
