@php
    // One toast per new support reply, on the opener's next page load.
    // Not on the support pages themselves, where the reply is already in view.
    $user = auth()->user();
    $replied = collect();

    if ($user && $user->shop_id && ! $user->is_super_admin && ! request()->routeIs('support.*')) {
        $replied = \App\Models\SupportTicket::query()
            ->where('user_id', $user->id)
            ->unreadForShop()
            ->where(fn ($q) => $q->whereNull('opener_notified_at')->orWhereColumn('last_admin_message_at', '>', 'opener_notified_at'))
            ->pluck('id');

        if ($replied->isNotEmpty()) {
            \App\Models\SupportTicket::whereKey($replied)->update(['opener_notified_at' => now()]);
        }
    }

    $message = $replied->count() === 1
        ? 'Support replied to your ticket #'.$replied->first().'. Open Help & Support to read it.'
        : 'Support replied to '.$replied->count().' of your tickets. Open Help & Support to read them.';
@endphp

@if ($replied->isNotEmpty())
    <div x-data x-init="$nextTick(() => $store.toasts.push({ type: 'info', message: @js($message) }))" data-support-reply-notice></div>
@endif
