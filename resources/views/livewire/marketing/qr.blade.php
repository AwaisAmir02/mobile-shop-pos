<?php

use App\Enums\PostKind;
use App\Enums\WhatsAppOrderStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\MarketingPost;
use App\Models\WhatsAppOrder;
use App\Services\Marketing\PostComposer;
use App\Services\Marketing\QrCodeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('QR Codes')] class extends Component
{
    use Toasts;

    public string $title = '';
    public string $mode = QrCodeService::MODE_CATALOG;

    public function mount(): void
    {
        $shop = Auth::user()->shop;
        abort_unless($shop?->whatsapp_enabled || $shop?->social_enabled, 403);
    }

    public function create(PostComposer $composer): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'in:'.QrCodeService::MODE_CHAT.','.QrCodeService::MODE_CATALOG],
        ]);

        if (PostComposer::whatsappNumber(Auth::user()->shop) === null) {
            $this->addError('title', 'Connect WhatsApp or add a phone number to your shop first.');

            return;
        }

        $qr = $composer->createQrCode(Auth::user()->shop, Auth::id(), $this->title);
        $qr->update(['caption' => QrCodeService::prefillText($this->mode, $qr->refTag())]);

        $this->reset('title');
        $this->toastSuccess('QR code created.');
    }

    public function with(): array
    {
        $qrCodes = app(QrCodeService::class);
        $shop = Auth::user()->shop;
        $codes = MarketingPost::query()->where('kind', PostKind::QrCode->value)->latest('id')->get();
        $orders = WhatsAppOrder::query()
            ->whereIn('origin_ref', $codes->pluck('ref_code'))
            ->where('status', '!=', WhatsAppOrderStatus::Collecting->value)
            ->get(['origin_ref', 'status'])
            ->groupBy('origin_ref');

        return [
            'codes' => $codes->map(fn (MarketingPost $qr) => [
                'qr' => $qr,
                'link' => $link = $qrCodes->link($shop, $qr),
                'image' => $link ? $qrCodes->dataUri($link, 5) : null,
                'orders' => ($orders[$qr->ref_code] ?? collect())->count(),
                'accepted' => ($orders[$qr->ref_code] ?? collect())->where('status', WhatsAppOrderStatus::Accepted)->count(),
            ]),
            'hasNumber' => PostComposer::whatsappNumber($shop) !== null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.qr" />

    <x-ui.card title="New QR code" description="Print it for your counter, shop window or packaging. Walk-in customers scan it to open your WhatsApp — every order that comes through it is counted here.">
        <form wire:submit="create" class="grid gap-4 sm:grid-cols-3 sm:items-end">
            <x-ui.field label="Name (for you)" name="title" for="qr-title">
                <x-ui.input wire:model="title" id="qr-title" placeholder="e.g. Counter, Window, Box insert" />
            </x-ui.field>
            <x-ui.field label="Opens" name="mode" for="qr-mode">
                <x-ui.select wire:model="mode" id="qr-mode">
                    <option value="{{ QrCodeService::MODE_CATALOG }}">Product catalog</option>
                    <option value="{{ QrCodeService::MODE_CHAT }}">Chat with shop</option>
                </x-ui.select>
            </x-ui.field>
            <div><x-ui.button type="submit" :disabled="! $hasNumber">Create QR code</x-ui.button></div>
        </form>
        @unless ($hasNumber)
            <p class="mt-3 text-sm text-amber-700">Connect WhatsApp (Settings → Integrations) or add a phone number to your shop first.</p>
        @endunless
    </x-ui.card>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($codes as $row)
            <x-ui.card wire:key="qr-{{ $row['qr']->id }}">
                <div class="flex items-start gap-4">
                    @if ($row['image'])
                        <img src="{{ $row['image'] }}" alt="QR code for {{ $row['qr']->title }}" class="h-28 w-28 rounded border border-slate-200">
                    @endif
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ $row['qr']->title }}</p>
                        <p class="text-xs text-slate-500">{{ str_starts_with((string) $row['qr']->caption, 'CATALOG') ? 'Opens product catalog' : 'Opens chat' }} · {{ $row['qr']->refTag() }}</p>
                        <p class="mt-2 text-sm text-slate-700"><span class="font-semibold">{{ $row['orders'] }}</span> {{ \Illuminate\Support\Str::plural('order', $row['orders']) }} · {{ $row['accepted'] }} accepted</p>
                    </div>
                </div>
                @if ($row['link'])
                    <div class="mt-4 flex gap-2">
                        <a href="{{ route('marketing.qr.download', ['post' => $row['qr'], 'format' => 'pdf']) }}"><x-ui.button size="sm">Print poster (PDF)</x-ui.button></a>
                        <a href="{{ route('marketing.qr.download', ['post' => $row['qr'], 'format' => 'png']) }}"><x-ui.button size="sm" variant="secondary">PNG</x-ui.button></a>
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>
</div>
