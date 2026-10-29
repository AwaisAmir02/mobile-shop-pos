<x-site.layout title="Finish setting up your shop" description="Finish creating your HisabOKitab shop.">
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#e2e8f0_1px,transparent_1px),linear-gradient(to_bottom,#e2e8f0_1px,transparent_1px)] bg-[size:44px_44px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_50%,transparent_100%)] opacity-50"></div>
        </div>

        <div class="mx-auto max-w-6xl px-4 pb-24 pt-12 sm:px-6 lg:px-8 lg:pt-16">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-accent-600">Almost there</p>
                <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Tell us about your shop</h1>
            </div>

            <div class="mt-10">
                <livewire:signup.complete :plan="request()->query('plan')" />
            </div>
        </div>
    </section>
</x-site.layout>
