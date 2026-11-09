@props([
    'messages',
    // Admin sees the thread from the support side: their own messages on the right.
    'admin' => false,
])

@php $attachmentRoute = $admin ? 'admin.support.attachment' : 'support.attachment'; @endphp

<ol {{ $attributes->class(['space-y-4']) }} aria-label="Messages">
    @foreach ($messages as $message)
        @php
            $mine = $admin ? $message->from_admin : ! $message->from_admin;
            $author = $message->from_admin
                ? ($admin ? ($message->user?->name ?? 'Support') : config('app.name').' Support')
                : ($message->user?->name ?? 'Removed user');
        @endphp
        <li wire:key="support-message-{{ $message->id }}" data-support-message data-from-admin="{{ $message->from_admin ? '1' : '0' }}" @class(['flex', 'justify-end' => $mine])>
            <div class="min-w-0 max-w-[90%] sm:max-w-xl">
                <p @class(['mb-1 text-xs text-ink-faint', 'text-right' => $mine])>
                    <span class="font-semibold text-ink-muted">{{ $author }}</span>
                    · <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}">{{ $message->created_at->diffForHumans() }}</time>
                </p>
                <div @class([
                    'rounded-2xl px-4 py-3 text-sm',
                    'rounded-tr-md bg-primary-soft text-ink' => $mine,
                    'rounded-tl-md border border-line bg-surface text-ink' => ! $mine,
                    'ring-1 ring-inset ring-info/20' => $message->from_admin && ! $admin,
                ])>
                    @if ($message->body)
                        <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                    @endif

                    @if ($message->attachments->isNotEmpty())
                        <ul @class(['flex flex-wrap gap-2', 'mt-3' => $message->body])>
                            @foreach ($message->attachments as $attachment)
                                <li>
                                    <a href="{{ route($attachmentRoute, $attachment) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-line bg-surface focus-visible:ring-2 focus-visible:ring-link" title="Open {{ $attachment->original_name }}">
                                        <img src="{{ route($attachmentRoute, $attachment) }}" alt="{{ $attachment->original_name ?: 'Attached photo' }}" loading="lazy" class="h-28 w-28 object-cover">
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </li>
    @endforeach
</ol>
