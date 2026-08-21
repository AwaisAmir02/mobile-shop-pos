@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-0.5']) }}>
        @foreach ((array) $messages as $message)
            <li class="text-sm text-red-600">{{ $message }}</li>
        @endforeach
    </ul>
@endif
