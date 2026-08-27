<div
    x-data
    class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-2"
            x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border p-4 shadow-card"
            :class="{
                'border-emerald-300 bg-emerald-50': toast.type === 'success',
                'border-red-300 bg-red-50': toast.type === 'error',
                'border-slate-200 bg-white': toast.type === 'info',
            }"
        >
            <span
                class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white"
                :class="{
                    'bg-emerald-500': toast.type === 'success',
                    'bg-red-500': toast.type === 'error',
                    'bg-slate-400': toast.type === 'info',
                }"
            >
                <svg x-show="toast.type === 'success'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                <svg x-show="toast.type === 'error'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <svg x-show="toast.type === 'info'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </span>

            <p class="flex-1 pt-0.5 text-sm font-medium text-slate-700" x-text="toast.message"></p>

            <button @click="$store.toasts.remove(toast.id)" class="shrink-0 text-slate-400 transition hover:text-slate-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>
