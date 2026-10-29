<x-site.layout title="Create your shop" description="Create your HisabOKitab shop in a few minutes — choose a plan, add your details and start selling the same day.">
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#e2e8f0_1px,transparent_1px),linear-gradient(to_bottom,#e2e8f0_1px,transparent_1px)] bg-[size:44px_44px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_50%,transparent_100%)] opacity-50"></div>
            <div class="site-drift absolute -left-32 -top-24 h-96 w-96 rounded-full bg-brand-300/25 blur-3xl"></div>
        </div>

        <div class="mx-auto max-w-6xl px-4 pb-24 pt-12 sm:px-6 lg:px-8 lg:pt-16">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">Get started</p>
                <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Create your shop</h1>
                <p class="mt-3 text-slate-600">Takes about two minutes. You’ll land in your own dashboard straight away.</p>
            </div>

            @if (session('oauth_error'))
                <p class="mx-auto mt-8 max-w-2xl rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-center text-sm text-red-800" role="alert">{{ session('oauth_error') }}</p>
            @endif

            <div class="mt-10">
                <livewire:signup.form :plan="request()->query('plan')" />
            </div>
        </div>
    </section>
</x-site.layout>
