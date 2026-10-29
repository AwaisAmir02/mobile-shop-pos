<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\CustomerReview;
use App\Models\Product;
use App\Models\RestockSubscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WhatsAppChatbot
{
    public const PAGE_SIZE = 9;

    public const MAX_LINE_QUANTITY = 50;

    public const HANDOFF_HOURS = 12;

    public const SEARCH_LIMIT = 9;

    protected WhatsAppClient $client;

    protected Shop $shop;

    protected WhatsAppContact $contact;

    public function __construct(protected ShopChannelConnection $connection, ?WhatsAppClient $client = null)
    {
        $this->client = $client ?? WhatsAppClient::for($connection);
        $this->shop = Shop::findOrFail($connection->shop_id);
    }

    public function handleInbound(array $message, ?string $profileName): void
    {
        $this->contact = $this->resolveContact((string) $message['from'], $profileName);

        if (isset($message['referral'])) {
            $this->remember(['referral' => $message['referral']]);
        }

        $input = $this->parseInput($message);

        if ($input['text'] !== null && preg_match('/\bREF[-\s]?([A-Z0-9]{4,12})\b/i', $input['text'], $match)) {
            $this->remember(['origin_ref' => strtoupper($match[1])]);
            $input['text'] = trim(preg_replace('/\bREF[-\s]?[A-Z0-9]{4,12}\b/i', '', $input['text']));
        }

        if ($this->contact->language === null && ($detected = WhatsAppLanguage::detect($input['text']))) {
            $this->contact->language = $detected;
            $this->contact->save();
        }

        $command = strtolower((string) $input['text']);

        if ($this->isHandedOff() && ! in_array($command, ['menu', 'bot', 'urdu', 'english'], true)) {
            return;
        }

        $this->route($input);
    }

    public function handleBusinessEcho(string $customerWaId): void
    {
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('wa_id', $customerWaId)
            ->first();

        if ($contact !== null) {
            $this->contact = $contact;
            $this->remember(['handoff_until' => now()->addHours(self::HANDOFF_HOURS)->toIso8601String()]);
        }
    }

    protected function t(string $key, array $replace = []): string|array
    {
        return WhatsAppLanguage::t($this->contact, $key, $replace);
    }

    protected function resolveContact(string $waId, ?string $profileName): WhatsAppContact
    {
        $contact = WhatsAppContact::withoutGlobalScope(ShopScope::class)->firstOrNew([
            'shop_id' => $this->shop->id,
            'wa_id' => $waId,
        ]);

        $contact->shop_channel_connection_id = $this->connection->id;
        $contact->last_inbound_at = now();

        if ($profileName) {
            $contact->profile_name = $profileName;
        }

        if ($contact->customer_id === null) {
            $contact->customer_id = Customer::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $this->shop->id)
                ->where('phone_normalized', PhoneNumber::normalize($waId))
                ->value('id');
        }

        $contact->save();

        return $contact;
    }

    protected function parseInput(array $message): array
    {
        $type = $message['type'] ?? 'unknown';

        return match ($type) {
            'text' => ['id' => null, 'text' => trim((string) ($message['text']['body'] ?? ''))],
            'interactive' => [
                'id' => $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null,
                'text' => null,
            ],
            'button' => ['id' => $message['button']['payload'] ?? null, 'text' => null],
            default => ['id' => null, 'text' => null],
        };
    }

    protected function route(array $input): void
    {
        $id = $input['id'];
        $text = $input['text'];
        $command = strtolower((string) $text);
        $step = $this->contact->bot_state['step'] ?? 'idle';

        if ($id === null) {
            if (in_array($command, ['urdu', 'اردو'], true) || in_array($command, ['english', 'eng'], true)) {
                $this->contact->language = in_array($command, ['urdu', 'اردو'], true) ? 'ur' : 'en';
                $this->setStep('idle');
                $this->client->sendText($this->contact->wa_id, $this->t('language_set'));

                return;
            }

            if (in_array($command, ['stop', 'unsubscribe'], true)) {
                $this->optOut();

                return;
            }

            if (in_array($command, ['help', 'agent'], true)) {
                $this->handOff();

                return;
            }

            if (in_array($command, ['menu', 'hi', 'hello', 'salam', 'start', 'bot', 'aoa', 'assalam o alaikum'], true) || $text === '') {
                $this->setStep('idle');
                $this->sendWelcome();

                return;
            }

            if (in_array($command, ['catalog', 'catalogue', 'products'], true)) {
                $this->setStep('idle');
                $this->sendCatalog(1);

                return;
            }

            if ($step === 'awaiting_quantity' && $text !== null && ctype_digit($text)) {
                $this->addToCart((int) ($this->contact->bot_state['product_id'] ?? 0), (int) $text);

                return;
            }

            if ($step === 'awaiting_address' && $text !== null) {
                $this->captureAddress($text);

                return;
            }

            if ($step === 'awaiting_review_comment' && $text !== null) {
                $this->captureReviewComment($text);

                return;
            }

            if ($text !== null && mb_strlen($text) >= 2) {
                $this->search($text);

                return;
            }

            $this->sendWelcome();

            return;
        }

        match (true) {
            $id === 'browse' => $this->sendCatalog(1),
            str_starts_with($id, 'browse:page:') => $this->sendCatalog((int) substr($id, 12)),
            str_starts_with($id, 'product:') => $this->sendProduct((int) substr($id, 8)),
            str_starts_with($id, 'qty:') => $this->addToCart(...array_map('intval', array_slice(explode(':', $id), 1, 2))),
            str_starts_with($id, 'notify:') => $this->subscribeRestock((int) substr($id, 7)),
            str_starts_with($id, 'rate:') => $this->captureRating((int) substr($id, 5)),
            str_starts_with($id, 'consent:') => $this->captureConsent(substr($id, 8) === 'yes'),
            $id === 'cart' => $this->sendCart(),
            $id === 'checkout' => $this->startCheckout(),
            $id === 'confirm' => $this->confirmOrder(),
            $id === 'clear' => $this->clearCart(),
            $id === 'optin' => $this->optIn(),
            $id === 'agent' => $this->handOff(),
            default => $this->sendWelcome(),
        };
    }

    protected function sendWelcome(): void
    {
        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('welcome', ['shop' => $this->shop->invoiceDisplayName()]),
            ['browse' => $this->t('btn_browse'), 'cart' => $this->t('btn_cart'), 'agent' => $this->t('btn_agent')],
        );
    }

    protected function listedQuery()
    {
        return Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_listed', true);
    }

    protected function listedProducts(): Collection
    {
        return $this->listedQuery()->where('stock_quantity', '>', 0)->orderBy('name')->get();
    }

    protected function findListedProduct(int $productId): ?Product
    {
        return $this->listedQuery()->whereKey($productId)->first();
    }

    protected function productRow(Product $product): array
    {
        return [
            'id' => 'product:'.$product->id,
            'title' => $product->name,
            'description' => 'Rs '.CustomerNotifications::money($product->currentSellingPrice()).' · '
                .($product->stock_quantity > 0 ? $this->t('in_stock_tag', ['stock' => $product->stock_quantity]) : $this->t('out_of_stock_tag')),
        ];
    }

    protected function sendCatalog(int $page): void
    {
        $products = $this->listedProducts();

        if ($products->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('no_products'));

            return;
        }

        $page = max(1, $page);
        $slice = $products->forPage($page, self::PAGE_SIZE);

        if ($slice->isEmpty()) {
            $page = 1;
            $slice = $products->forPage(1, self::PAGE_SIZE);
        }

        $rows = $slice->map(fn (Product $product) => $this->productRow($product))->values()->all();

        if ($products->count() > $page * self::PAGE_SIZE) {
            $rows[] = ['id' => 'browse:page:'.($page + 1), 'title' => $this->t('more_products')];
        }

        $this->client->sendList($this->contact->wa_id, $this->t('choose_product'), $this->t('btn_view_products'), $rows);
    }

    protected function search(string $query): void
    {
        $terms = collect(preg_split('/\s+/', mb_strtolower($query)))->filter(fn ($t) => mb_strlen($t) >= 2)->take(4);

        $matches = $terms->isEmpty() ? collect() : $this->listedQuery()
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
                }
            })
            ->orderByDesc('stock_quantity')
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        if ($matches->count() === 1) {
            $this->sendProduct($matches->first()->id);

            return;
        }

        if ($matches->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('search_no_match', ['query' => mb_substr($query, 0, 40)]));
            $this->sendCatalog(1);

            return;
        }

        $this->client->sendList(
            $this->contact->wa_id,
            $this->t('search_results', ['query' => mb_substr($query, 0, 40)]),
            $this->t('btn_view_products'),
            $matches->map(fn (Product $product) => $this->productRow($product))->values()->all(),
        );
    }

    protected function sendProduct(int $productId): void
    {
        $product = $this->findListedProduct($productId);

        if ($product === null) {
            $this->client->sendText($this->contact->wa_id, $this->t('product_unavailable'));
            $this->sendCatalog(1);

            return;
        }

        if ($product->stock_quantity < 1) {
            $this->client->sendButtons(
                $this->contact->wa_id,
                $this->t('out_of_stock_offer', ['name' => $product->name]),
                ['notify:'.$product->id => $this->t('btn_notify_me'), 'browse' => $this->t('btn_back')],
            );

            return;
        }

        $caption = $this->t('product_caption', [
            'name' => $product->name,
            'price' => CustomerNotifications::money($product->currentSellingPrice()),
            'stock' => $product->stock_quantity,
        ]);
        $imageUrl = $product->imageUrl();
        $hasImage = $imageUrl !== null && str_starts_with($imageUrl, 'https://');

        if ($hasImage) {
            $this->client->sendImage($this->contact->wa_id, $imageUrl, $caption);
        }

        $this->setStep('awaiting_quantity', ['product_id' => $product->id]);

        $buttons = ['qty:'.$product->id.':1' => $this->t('btn_add_1')];

        if ($product->stock_quantity >= 2) {
            $buttons['qty:'.$product->id.':2'] = $this->t('btn_add_2');
        }

        $buttons['browse'] = $this->t('btn_back');

        $this->client->sendButtons($this->contact->wa_id, ($hasImage ? '' : $caption."\n\n").$this->t('how_many'), $buttons);
    }

    protected function subscribeRestock(int $productId): void
    {
        $product = $this->findListedProduct($productId);

        if ($product === null) {
            $this->client->sendText($this->contact->wa_id, $this->t('product_unavailable'));

            return;
        }

        RestockSubscription::withoutGlobalScope(ShopScope::class)->firstOrCreate([
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'whatsapp_contact_id' => $this->contact->id,
            'notified_at' => null,
            'cancelled_at' => null,
        ]);

        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('notify_subscribed', ['name' => $product->name]),
            ['browse' => $this->t('btn_browse')],
        );
    }

    protected function collectingOrder(bool $create): ?WhatsAppOrder
    {
        $order = WhatsAppOrder::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_contact_id', $this->contact->id)
            ->where('status', WhatsAppOrderStatus::Collecting->value)
            ->latest('id')
            ->first();

        if ($order === null && $create) {
            $order = WhatsAppOrder::create([
                'shop_id' => $this->shop->id,
                'whatsapp_contact_id' => $this->contact->id,
                'customer_id' => $this->contact->customer_id,
                'status' => WhatsAppOrderStatus::Collecting,
                'quoted_total' => 0,
            ]);
        }

        return $order;
    }

    protected function orderItems(WhatsAppOrder $order): Collection
    {
        return WhatsAppOrderItem::withoutGlobalScope(ShopScope::class)
            ->where('whatsapp_order_id', $order->id)
            ->orderBy('id')
            ->get();
    }

    protected function addToCart(int $productId, int $quantity = 1): void
    {
        $product = $this->findListedProduct($productId);

        if ($product === null || $product->stock_quantity < 1) {
            $this->client->sendText($this->contact->wa_id, $this->t('product_unavailable'));

            return;
        }

        if ($quantity < 1 || $quantity > self::MAX_LINE_QUANTITY) {
            $this->client->sendText($this->contact->wa_id, $this->t('qty_range', ['max' => min(self::MAX_LINE_QUANTITY, max(1, $product->stock_quantity))]));

            return;
        }

        $order = $this->collectingOrder(true);

        $item = WhatsAppOrderItem::withoutGlobalScope(ShopScope::class)->firstOrNew([
            'whatsapp_order_id' => $order->id,
            'product_id' => $product->id,
        ]);

        $newQuantity = ($item->exists ? $item->quantity : 0) + $quantity;

        if ($newQuantity > $product->stock_quantity) {
            $this->client->sendText($this->contact->wa_id, $item->exists
                ? $this->t('only_available_in_cart', ['stock' => $product->stock_quantity, 'name' => $product->name, 'qty' => $item->quantity])
                : $this->t('only_available', ['stock' => $product->stock_quantity, 'name' => $product->name]));

            return;
        }

        $item->fill([
            'shop_id' => $this->shop->id,
            'product_name' => $product->name,
            'quantity' => $newQuantity,
            'quoted_unit_price' => $product->currentSellingPrice(),
        ])->save();

        $order->recalculateQuotedTotal();
        $this->setStep('idle');

        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('added', ['qty' => $quantity, 'name' => $product->name, 'total' => CustomerNotifications::money((float) $order->quoted_total)]),
            ['browse' => $this->t('btn_add_more'), 'checkout' => $this->t('btn_checkout'), 'clear' => $this->t('btn_clear')],
        );
    }

    protected function cartSummary(WhatsAppOrder $order): string
    {
        $lines = $this->orderItems($order)->map(
            fn (WhatsAppOrderItem $item) => "• {$item->quantity} × {$item->product_name} — Rs ".CustomerNotifications::money($item->lineTotal())
        )->implode("\n");

        return $lines."\n\n".$this->t('total_line', ['total' => CustomerNotifications::money((float) $order->quoted_total)]);
    }

    protected function sendCart(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || $this->orderItems($order)->isEmpty()) {
            $this->client->sendButtons($this->contact->wa_id, $this->t('cart_empty'), ['browse' => $this->t('btn_browse')]);

            return;
        }

        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('your_cart', ['summary' => $this->cartSummary($order)]),
            ['checkout' => $this->t('btn_checkout'), 'browse' => $this->t('btn_add_more'), 'clear' => $this->t('btn_clear')],
        );
    }

    protected function startCheckout(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || $this->orderItems($order)->isEmpty()) {
            $this->client->sendButtons($this->contact->wa_id, $this->t('cart_empty'), ['browse' => $this->t('btn_browse')]);

            return;
        }

        $this->setStep('awaiting_address');
        $this->client->sendText($this->contact->wa_id, $this->t('ask_address'));
    }

    protected function captureAddress(string $text): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null) {
            $this->setStep('idle');
            $this->sendWelcome();

            return;
        }

        $order->update(['delivery_address' => mb_substr(strtolower($text) === 'pickup' ? $this->t('pickup_label') : $text, 0, 500)]);
        $this->setStep('confirming');

        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('confirm_prompt', ['summary' => $this->cartSummary($order), 'address' => $order->delivery_address]),
            ['confirm' => $this->t('btn_confirm'), 'clear' => $this->t('btn_cancel')],
        );
    }

    protected function confirmOrder(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || $this->orderItems($order)->isEmpty()) {
            $this->client->sendButtons($this->contact->wa_id, $this->t('cart_empty'), ['browse' => $this->t('btn_browse')]);

            return;
        }

        $state = $this->contact->bot_state ?? [];

        $order->update([
            'status' => WhatsAppOrderStatus::Pending,
            'submitted_at' => now(),
            'customer_id' => $order->customer_id ?? $this->contact->customer_id,
            'origin_ref' => $state['origin_ref'] ?? null,
            'referral' => $state['referral'] ?? null,
        ]);

        $this->setStep('idle', [], ['origin_ref', 'referral']);

        $this->client->sendText($this->contact->wa_id, $this->t('order_received', ['order' => $order->orderNumber()]));
    }

    protected function clearCart(): void
    {
        $order = $this->collectingOrder(false);

        if ($order !== null) {
            DB::transaction(function () use ($order) {
                WhatsAppOrderItem::withoutGlobalScope(ShopScope::class)->where('whatsapp_order_id', $order->id)->delete();
                $order->delete();
            });
        }

        $this->setStep('idle');
        $this->client->sendButtons($this->contact->wa_id, $this->t('cart_cleared'), ['browse' => $this->t('btn_browse')]);
    }

    protected function latestReviewableOrderId(): ?int
    {
        return WhatsAppOrder::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_contact_id', $this->contact->id)
            ->whereNotNull('review_requested_at')
            ->latest('review_requested_at')
            ->value('id');
    }

    protected function captureRating(int $rating): void
    {
        $rating = max(1, min(5, $rating));

        $review = CustomerReview::withoutGlobalScope(ShopScope::class)->create([
            'shop_id' => $this->shop->id,
            'whatsapp_contact_id' => $this->contact->id,
            'customer_id' => $this->contact->customer_id,
            'whatsapp_order_id' => $this->latestReviewableOrderId(),
            'rating' => $rating,
            'display_name' => $this->contact->customer?->name ?? $this->contact->profile_name,
            'status' => 'collecting',
        ]);

        $this->setStep('awaiting_review_comment', ['review_id' => $review->id]);
        $this->client->sendText($this->contact->wa_id, $this->t('review_ask_comment'));
    }

    protected function currentReview(): ?CustomerReview
    {
        $reviewId = $this->contact->bot_state['review_id'] ?? null;

        return $reviewId ? CustomerReview::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_contact_id', $this->contact->id)
            ->find($reviewId) : null;
    }

    protected function captureReviewComment(string $text): void
    {
        $review = $this->currentReview();

        if ($review === null) {
            $this->setStep('idle');
            $this->sendWelcome();

            return;
        }

        $skip = in_array(strtolower(trim($text)), (array) $this->t('skip_words'), true);
        $review->update(['comment' => $skip ? null : mb_substr($text, 0, 1000)]);

        if ($review->rating <= 3) {
            $review->update(['status' => 'complete']);
            $this->setStep('idle', [], ['review_id']);
            $this->client->sendText($this->contact->wa_id, $this->t('review_sorry'));

            return;
        }

        $this->setStep('awaiting_review_consent', ['review_id' => $review->id]);
        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('review_ask_consent'),
            ['consent:yes' => $this->t('btn_yes'), 'consent:no' => $this->t('btn_no')],
        );
    }

    protected function captureConsent(bool $consent): void
    {
        $review = $this->currentReview();

        if ($review === null) {
            $this->sendWelcome();

            return;
        }

        $review->update([
            'publish_consent' => $consent,
            'consent_at' => $consent ? now() : null,
            'status' => 'complete',
        ]);

        $this->setStep('idle', [], ['review_id']);
        $this->client->sendText($this->contact->wa_id, $this->t('review_thanks'));
    }

    protected function optIn(): void
    {
        $this->contact->update(['marketing_opt_in_at' => now(), 'marketing_opt_out_at' => null]);
        $this->client->sendText($this->contact->wa_id, $this->t('opted_in'));
    }

    protected function optOut(): void
    {
        $this->contact->update(['marketing_opt_out_at' => now()]);
        $this->client->sendText($this->contact->wa_id, $this->t('opted_out'));
    }

    protected function handOff(): void
    {
        $this->remember(['handoff_until' => now()->addHours(self::HANDOFF_HOURS)->toIso8601String()]);
        $this->client->sendText($this->contact->wa_id, $this->t('handoff'));
    }

    protected function isHandedOff(): bool
    {
        $until = $this->contact->bot_state['handoff_until'] ?? null;

        return $until !== null && now()->lt($until);
    }

    protected function setStep(string $step, array $extra = [], array $forget = []): void
    {
        $state = $this->contact->bot_state ?? [];
        unset($state['product_id'], $state['handoff_until']);

        foreach ($forget as $key) {
            unset($state[$key]);
        }

        $this->contact->bot_state = array_merge($state, ['step' => $step], $extra);
        $this->contact->save();
    }

    protected function remember(array $values): void
    {
        $this->contact->bot_state = array_merge($this->contact->bot_state ?? [], $values);
        $this->contact->save();
    }
}
