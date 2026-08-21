@props(['headers' => []])

<div {{ $attributes->class(['overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card']) }}>
    <table class="min-w-full divide-y divide-slate-200">
        @if (count($headers))
            <thead class="bg-slate-50">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-slate-100">
            {{ $slot }}
        </tbody>
    </table>
</div>
