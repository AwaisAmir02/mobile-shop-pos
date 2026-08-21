@props(['type' => 'text'])

<input type="{{ $type }}" {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 py-2.5 px-3.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-500']) }}>
