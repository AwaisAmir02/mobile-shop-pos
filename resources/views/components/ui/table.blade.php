@props(['headers' => []])

<div {{ $attributes->class(['ui-table overflow-x-auto rounded-2xl border border-line bg-surface shadow-soft']) }}>
    <table class="min-w-full divide-y divide-line">
        @if (count($headers))
            <thead class="bg-surface-sunken/70">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-line">
            {{ $slot }}
        </tbody>
    </table>
</div>
