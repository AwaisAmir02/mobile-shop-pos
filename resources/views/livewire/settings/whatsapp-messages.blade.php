<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Shop;
use App\Support\WhatsAppMessageSuggestions;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    /** @var array<string, array{en: string, ur: string}> blank means "use the default text" */
    public array $texts = [];

    public function mount(): void
    {
        $this->authorizeMessages();

        $shop = $this->shop();

        foreach (array_keys(WhatsAppMessageSuggestions::MESSAGES) as $key) {
            foreach (['en', 'ur'] as $language) {
                $this->texts[$key][$language] = (string) $shop->whatsappMessage($key, $language);
            }
        }
    }

    protected function authorizeMessages(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && Auth::user()->shop?->whatsapp_enabled, 403);
    }

    protected function shop(): Shop
    {
        return Shop::findOrFail(Auth::user()->shop_id);
    }

    public function useSuggestion(string $key, string $language, int $index): void
    {
        $suggestion = WhatsAppMessageSuggestions::all()[$key][$index] ?? null;
        abort_if($suggestion === null || ! in_array($language, ['en', 'ur'], true), 404);

        $this->texts[$key][$language] = $suggestion[$language];
    }

    public function useDefault(string $key, string $language): void
    {
        abort_unless(isset($this->texts[$key][$language]), 404);

        $this->texts[$key][$language] = '';
    }

    public function save(): void
    {
        $this->authorizeMessages();

        $rules = [];
        foreach (array_keys(WhatsAppMessageSuggestions::MESSAGES) as $key) {
            foreach (['en', 'ur'] as $language) {
                $rules["texts.$key.$language"] = ['nullable', 'string', 'max:'.WhatsAppMessageSuggestions::MAX_LENGTH];
            }
        }
        $this->validate($rules, [], ['texts.*.*' => 'message']);

        $stored = [];
        foreach (array_keys(WhatsAppMessageSuggestions::MESSAGES) as $key) {
            foreach (['en', 'ur'] as $language) {
                $text = trim((string) ($this->texts[$key][$language] ?? ''));

                if ($text !== '') {
                    $stored[$key][$language] = $text;
                }
            }
        }

        $shop = $this->shop();
        $shop->whatsapp_messages = $stored ?: null;
        $shop->save();

        $this->toastSuccess('WhatsApp messages saved.');
    }

    public function with(): array
    {
        $shopName = $this->shop()->invoiceDisplayName();

        return [
            'messages' => [
                'welcome' => ['title' => 'Welcome message', 'help' => 'Sent when a customer says hi or types MENU. Use {shop} for your shop name. Browse / cart / talk-to-shop buttons are added below it automatically.'],
                'order_received' => ['title' => 'Thank-you message', 'help' => 'Sent when a customer confirms an order. Use {order} for the order number and {shop} for your shop name.'],
            ],
            'suggestions' => WhatsAppMessageSuggestions::all(),
            'defaults' => [
                'welcome' => ['en' => __('whatsapp.welcome', ['shop' => $shopName], 'en'), 'ur' => __('whatsapp.welcome', ['shop' => $shopName], 'ur')],
                'order_received' => ['en' => __('whatsapp.order_received', ['order' => 'WA-00001'], 'en'), 'ur' => __('whatsapp.order_received', ['order' => 'WA-00001'], 'ur')],
            ],
            'languages' => ['en' => 'English', 'ur' => 'Urdu (Roman)'],
        ];
    }
}; ?>

<x-ui.card title="WhatsApp chatbot messages" description="Write your own welcome and thank-you messages, pick a suggestion and edit it, or leave a box empty to use the standard text shown in it.">
    <form wire:submit="save" class="space-y-8">
        @foreach ($messages as $key => $message)
            <div wire:key="message-{{ $key }}">
                <h3 class="text-sm font-semibold text-slate-900">{{ $message['title'] }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $message['help'] }}</p>

                <div class="mt-4 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @foreach ($languages as $language => $languageLabel)
                        <div wire:key="message-{{ $key }}-{{ $language }}">
                            <label for="text-{{ $key }}-{{ $language }}" class="block text-sm font-medium text-slate-700">{{ $languageLabel }}</label>
                            <textarea
                                wire:model="texts.{{ $key }}.{{ $language }}"
                                id="text-{{ $key }}-{{ $language }}"
                                rows="6"
                                maxlength="{{ \App\Support\WhatsAppMessageSuggestions::MAX_LENGTH }}"
                                placeholder="{{ $defaults[$key][$language] }}"
                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500"
                            ></textarea>
                            <x-ui.error :messages="$errors->get('texts.'.$key.'.'.$language)" />

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-500">Suggestions:</span>
                                @foreach ($suggestions[$key] as $index => $suggestion)
                                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="useSuggestion('{{ $key }}', '{{ $language }}', {{ $index }})">{{ $suggestion['label'] }}</x-ui.button>
                                @endforeach
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="useDefault('{{ $key }}', '{{ $language }}')">Use standard text</x-ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="flex justify-end border-t border-slate-100 pt-5">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save messages</x-ui.button>
        </div>
    </form>
</x-ui.card>
