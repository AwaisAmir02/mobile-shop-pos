<?php

namespace App\Services\WhatsApp;

use App\Enums\LimitKey;
use App\Enums\QuantityUnit;
use App\Enums\WhatsAppOrderStatus;
use App\Enums\WhatsAppPaymentChoice;
use App\Models\AccessoryCategoryOption;
use App\Models\Customer;
use App\Models\CustomerReview;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RestockSubscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\Billing\Entitlements;
use App\Exceptions\UsageLimitReached;
use App\Support\PhoneNumber;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppChatbot
{
    public const PAGE_SIZE = 9;

    public const MAX_LINE_QUANTITY = 50;

    public const HANDOFF_HOURS = 12;

    public const SEARCH_LIMIT = 9;

    public const CATEGORY_PAGE_SIZE = 8;

    public const SUB_CATEGORY_PAGE_SIZE = 7;

    public const SLIP_MAX_BYTES = 5 * 1024 * 1024;

    public const SLIP_DISK = 'local';

    /** Payment slip types accepted, and the extension each is stored with. */
    public const SLIP_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

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

        if (in_array($message['type'] ?? null, ['image', 'document'], true)) {
            $this->orderingOn() ? $this->handleMedia($message) : $this->sendOrderingOff();

            return;
        }

        if (! $this->orderingOn() && ! $this->answersWithoutOrdering($input)) {
            $this->sendOrderingOff();

            return;
        }

        $this->route($input);
    }

    protected function orderingOn(): bool
    {
        return (bool) $this->shop->whatsapp_ordering_enabled;
    }

    /**
     * With ordering off the bot still handles the messaging side: review
     * ratings and comments, opt-in/out, language and asking for a person.
     * Browsing, the cart and checkout get the "not taking orders" reply.
     */
    protected function answersWithoutOrdering(array $input): bool
    {
        $id = $input['id'];
        $command = strtolower((string) $input['text']);

        if ($id !== null) {
            return str_starts_with($id, 'rate:') || str_starts_with($id, 'consent:') || in_array($id, ['optin', 'agent'], true);
        }

        return in_array($command, ['urdu', 'اردو', 'english', 'eng', 'stop', 'unsubscribe', 'help', 'agent'], true)
            || (($this->contact->bot_state['step'] ?? null) === 'awaiting_review_comment' && $input['text'] !== null);
    }

    protected function sendOrderingOff(): void
    {
        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('ordering_off', ['shop' => $this->shop->invoiceDisplayName()]),
            ['agent' => $this->t('btn_agent')],
        );
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

        $newlyLinked = false;

        if ($contact->customer_id === null) {
            $contact->customer_id = Customer::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $this->shop->id)
                ->where('phone_normalized', PhoneNumber::normalize($waId))
                ->value('id');
            $newlyLinked = $contact->customer_id !== null;
        }

        $contact->save();

        if ($newlyLinked) {
            $contact->syncDeliveryToCustomer();
        }

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
                $this->browse();

                return;
            }

            // "1.5" is a quantity attempt too, not a product search.
            if ($step === 'awaiting_quantity' && $text !== null && preg_match('/^\d+([.,]\d+)?$/', $text) === 1) {
                $this->addToCart((int) ($this->contact->bot_state['product_id'] ?? 0), str_replace(',', '.', $text));

                return;
            }

            if ($step === 'awaiting_address' && $text !== null) {
                // A conversation that was mid-checkout before structured details existed.
                $this->startCheckout();

                return;
            }

            if ($step === 'awaiting_payment_slip' && $text !== null && $this->collectingOrder(false) !== null) {
                $this->askSlip();

                return;
            }

            if ($step === 'awaiting_payment_choice' && $text !== null && in_array(strtolower($text), ['cash', 'cod', 'online'], true)) {
                $this->choosePayment(strtolower($text) === 'online');

                return;
            }

            if ($text !== null && in_array($step, ['awaiting_fulfilment', 'reviewing_saved_details', 'awaiting_delivery_name', 'awaiting_delivery_address', 'awaiting_delivery_area', 'awaiting_delivery_phone'], true)) {
                $this->captureCheckoutText($step, $text);

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
            $id === 'browse' => $this->browse(),
            str_starts_with($id, 'browse:page:') => $this->sendCatalog((int) substr($id, 12)),
            str_starts_with($id, 'cats:') => $this->browse((int) substr($id, 5)),
            str_starts_with($id, 'cat:') => $this->sendCategory(...array_map('intval', array_slice(explode(':', $id), 1, 2))),
            str_starts_with($id, 'list:') => $this->sendCatalog((int) (explode(':', $id)[2] ?? 1), (string) (explode(':', $id)[1] ?? '')),
            str_starts_with($id, 'product:') => $this->sendProduct((int) substr($id, 8)),
            str_starts_with($id, 'group:') => $this->sendVariants(...array_map('intval', array_slice(explode(':', $id), 1, 2))),
            str_starts_with($id, 'qty:') => $this->addToCart(...array_map('intval', array_slice(explode(':', $id), 1, 2))),
            str_starts_with($id, 'notify:') => $this->subscribeRestock((int) substr($id, 7)),
            str_starts_with($id, 'rate:') => $this->captureRating((int) substr($id, 5)),
            str_starts_with($id, 'consent:') => $this->captureConsent(substr($id, 8) === 'yes'),
            $id === 'cart' => $this->sendCart(),
            $id === 'checkout' => $this->startCheckout(),
            $id === 'ship:delivery' => $this->chooseFulfilment('delivery'),
            $id === 'ship:pickup' => $this->chooseFulfilment('pickup'),
            $id === 'details:use' => $this->useSavedDetails(),
            $id === 'details:update' => $this->askFulfilment(),
            $id === 'phone:whatsapp' => $this->useWhatsAppNumber(),
            $id === 'save:yes' => $this->answerSaveDetails(true),
            $id === 'save:no' => $this->answerSaveDetails(false),
            $id === 'pay:cash' => $this->choosePayment(false),
            $id === 'pay:online' => $this->choosePayment(true),
            $id === 'slip:later' => $this->sendSlipLater(),
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
            $this->shopText('welcome', ['{shop}' => $this->shop->invoiceDisplayName()], ['shop' => $this->shop->invoiceDisplayName()]),
            ['browse' => $this->t('btn_browse'), 'cart' => $this->t('btn_cart'), 'agent' => $this->t('btn_agent')],
        );
    }

    /** Ordering by weight or length isn't offered here yet, so only pieces can be reached. */
    protected function listedQuery()
    {
        return Product::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_listed', true)
            ->where('unit', QuantityUnit::Piece->value);
    }

    /** The catalogue: a variant group appears once, standing in for all its variants. */
    protected function listedProducts(): Collection
    {
        return $this->oneEntryPerGroup($this->listedQuery()->where('stock_quantity', '>', 0)->orderBy('name')->get());
    }

    protected function oneEntryPerGroup(Collection $products): Collection
    {
        return $products->unique(fn (Product $product) => $product->product_group_id !== null ? 'group-'.$product->product_group_id : 'product-'.$product->id)->values();
    }

    protected function findListedProduct(int $productId): ?Product
    {
        return $this->listedQuery()->whereKey($productId)->first();
    }

    protected function productRow(Product $product): array
    {
        if ($product->product_group_id !== null && ($group = $this->findGroup($product->product_group_id)) !== null) {
            return $this->groupRow($group);
        }

        return [
            'id' => 'product:'.$product->id,
            'title' => $product->name,
            'description' => $this->priceAndStock($product),
        ];
    }

    protected function priceAndStock(Product $product): string
    {
        return 'Rs '.CustomerNotifications::money($product->currentSellingPrice()).' · '
            .($product->stock_quantity > 0 ? $this->t('in_stock_tag', ['stock' => $product->stock_quantity]) : $this->t('out_of_stock_tag'));
    }

    protected function findGroup(int $groupId): ?ProductGroup
    {
        return ProductGroup::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->whereKey($groupId)
            ->first();
    }

    protected function listedVariants(ProductGroup $group): Collection
    {
        return $this->listedQuery()->where('product_group_id', $group->id)->orderBy('id')->get();
    }

    /** One catalogue row for a whole group, priced from its cheapest variant in stock. */
    protected function groupRow(ProductGroup $group): array
    {
        $variants = $this->listedVariants($group);
        $inStock = $variants->where('stock_quantity', '>', 0);
        $prices = Product::sellingPricesFor($inStock->isNotEmpty() ? $inStock : $variants);

        return [
            'id' => 'group:'.$group->id,
            'title' => $group->name,
            'description' => $prices === []
                ? $this->t('out_of_stock_tag')
                : $this->t('group_row', ['price' => CustomerNotifications::money(min($prices)), 'count' => $variants->count()]),
        ];
    }

    /**
     * The picker shown after choosing a group. List titles are cut at 24
     * characters, so each row shows only the variant's label ("M / Red"); a
     * label too long for that repeats in full in the description. Out-of-stock
     * variants stay listed: tapping one offers the restock alert.
     */
    protected function sendVariants(int $groupId, int $page = 1): void
    {
        $group = $this->findGroup($groupId);
        $variants = $group ? $this->listedVariants($group) : collect();

        if ($variants->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('product_unavailable'));
            $this->browse();

            return;
        }

        if ($variants->count() === 1) {
            $this->sendProduct($variants->first()->id);

            return;
        }

        $page = max(1, $page);
        $slice = $variants->forPage($page, self::PAGE_SIZE);

        if ($slice->isEmpty()) {
            $page = 1;
            $slice = $variants->forPage(1, self::PAGE_SIZE);
        }

        $rows = $slice->map(fn (Product $variant) => [
            'id' => 'product:'.$variant->id,
            'title' => (string) $variant->variant_label,
            'description' => (mb_strlen((string) $variant->variant_label) > 24 ? $variant->variant_label.' · ' : '').$this->priceAndStock($variant),
        ])->values()->all();

        if ($variants->count() > $page * self::PAGE_SIZE) {
            $rows[] = ['id' => 'group:'.$group->id.':'.($page + 1), 'title' => $this->t('more_options')];
        }

        $this->client->sendList($this->contact->wa_id, $this->t('choose_variant', ['name' => $group->name]), $this->t('btn_view_options'), $rows, $this->t('section_options'));
    }

    /**
     * Browse entry point. Shows the shop's Main Categories (only those with
     * something listed and in stock), plus an "All products" row. Falls back
     * to the flat list when there's nothing to choose between.
     */
    protected function browse(int $page = 1): void
    {
        $products = $this->listedProducts();

        if ($products->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('no_products'));

            return;
        }

        $groups = $this->mainCategories()
            ->map(fn (MainCategory $category) => ['category' => $category, 'count' => $products->where('type', $category->slug)->count()])
            ->filter(fn (array $group) => $group['count'] > 0)
            ->values();

        if ($groups->isEmpty()) {
            $this->sendCatalog(1);

            return;
        }

        if ($groups->count() === 1 && $groups[0]['count'] === $products->count()) {
            $this->sendCategory($groups[0]['category']->id);

            return;
        }

        $page = max(1, $page);
        $slice = $groups->forPage($page, self::CATEGORY_PAGE_SIZE);

        if ($slice->isEmpty()) {
            $page = 1;
            $slice = $groups->forPage(1, self::CATEGORY_PAGE_SIZE);
        }

        $rows = [['id' => 'browse:page:1', 'title' => $this->t('all_products'), 'description' => $this->t('product_count', ['count' => $products->count()])]];

        foreach ($slice as $group) {
            $rows[] = [
                'id' => 'cat:'.$group['category']->id,
                'title' => $group['category']->name,
                'description' => $this->t('product_count', ['count' => $group['count']]),
            ];
        }

        if ($groups->count() > $page * self::CATEGORY_PAGE_SIZE) {
            $rows[] = ['id' => 'cats:'.($page + 1), 'title' => $this->t('more_categories')];
        }

        $this->client->sendList($this->contact->wa_id, $this->t('choose_category'), $this->t('btn_view_categories'), $rows, $this->t('section_categories'));
    }

    /**
     * One Main Category. If any of its sub-categories have products, offer
     * them (plus "All" and, when needed, "Other"); otherwise go straight to
     * the category's product list.
     */
    protected function sendCategory(int $categoryId, int $page = 1): void
    {
        $category = $this->findMainCategory($categoryId);
        $products = $category ? $this->listedProducts()->where('type', $category->slug)->values() : collect();

        if ($category === null || $products->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('selection_empty'));
            $this->browse();

            return;
        }

        $subs = $this->subCategoriesOf($category)
            ->map(fn (AccessoryCategoryOption $option) => [
                'option' => $option,
                'count' => $products->filter(fn (Product $product) => $this->productSubCategory($product) === $option->name)->count(),
            ])
            ->filter(fn (array $sub) => $sub['count'] > 0)
            ->values();

        if ($subs->isEmpty()) {
            $this->sendCatalog(1, 'c'.$category->id);

            return;
        }

        $other = $this->otherProducts($category, $products)->count();
        $page = max(1, $page);
        $slice = $subs->forPage($page, self::SUB_CATEGORY_PAGE_SIZE);

        if ($slice->isEmpty()) {
            $page = 1;
            $slice = $subs->forPage(1, self::SUB_CATEGORY_PAGE_SIZE);
        }

        $rows = [['id' => 'list:c'.$category->id.':1', 'title' => $this->t('all_in_category', ['name' => $category->name]), 'description' => $this->t('product_count', ['count' => $products->count()])]];

        foreach ($slice as $sub) {
            $rows[] = ['id' => 'list:s'.$sub['option']->id.':1', 'title' => $sub['option']->name, 'description' => $this->t('product_count', ['count' => $sub['count']])];
        }

        if ($other > 0) {
            $rows[] = ['id' => 'list:o'.$category->id.':1', 'title' => $this->t('other_in_category'), 'description' => $this->t('product_count', ['count' => $other])];
        }

        if ($subs->count() > $page * self::SUB_CATEGORY_PAGE_SIZE) {
            $rows[] = ['id' => 'cat:'.$category->id.':'.($page + 1), 'title' => $this->t('more_categories')];
        }

        $this->client->sendList($this->contact->wa_id, $this->t('choose_sub_category', ['name' => $category->name]), $this->t('btn_view_categories'), $rows, $this->t('section_categories'));
    }

    /**
     * The product list. $scope narrows it: '' everything listed, 'c{id}' a
     * Main Category, 's{id}' a sub-category, 'o{id}' a Main Category's
     * products that match none of its sub-categories.
     */
    protected function sendCatalog(int $page, string $scope = ''): void
    {
        [$products, $heading] = $this->scopedProducts($scope);

        if ($products === null || ($products->isEmpty() && $scope !== '')) {
            $this->client->sendText($this->contact->wa_id, $this->t('selection_empty'));
            $this->browse();

            return;
        }

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
            $rows[] = ['id' => $scope === '' ? 'browse:page:'.($page + 1) : 'list:'.$scope.':'.($page + 1), 'title' => $this->t('more_products')];
        }

        $body = $heading === null ? $this->t('choose_product') : "*{$heading}*\n".$this->t('choose_product');

        $this->client->sendList($this->contact->wa_id, $body, $this->t('btn_view_products'), $rows);
    }

    /**
     * @return array{0: ?Collection, 1: ?string} the products (null when the
     *                                           scope points at nothing in this shop) and a heading
     */
    protected function scopedProducts(string $scope): array
    {
        $products = $this->listedProducts();

        if ($scope === '') {
            return [$products, null];
        }

        $kind = $scope[0];
        $id = (int) substr($scope, 1);

        if ($kind === 's') {
            $option = $this->findSubCategory($id);
            $category = $option ? $this->findMainCategory((int) $option->main_category_id) : null;

            return $category === null ? [null, null] : [
                $products->where('type', $category->slug)->filter(fn (Product $product) => $this->productSubCategory($product) === $option->name)->values(),
                $option->name,
            ];
        }

        $category = in_array($kind, ['c', 'o'], true) ? $this->findMainCategory($id) : null;

        if ($category === null) {
            return [null, null];
        }

        $inCategory = $products->where('type', $category->slug)->values();

        return $kind === 'c'
            ? [$inCategory, $category->name]
            : [$this->otherProducts($category, $inCategory), $this->t('other_in_category_heading', ['name' => $category->name])];
    }

    /**
     * Products link to a sub-category by name only (details.category, no
     * foreign key) — the same exact-text match Stock-In's filter uses.
     */
    protected function productSubCategory(Product $product): ?string
    {
        $name = $product->details['category'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * A category's products whose sub-category text matches none of its
     * current sub-categories: none set, a sub-category renamed after the
     * product was saved, or one since moved to another Main Category.
     */
    protected function otherProducts(MainCategory $category, Collection $inCategory): Collection
    {
        $names = $this->subCategoriesOf($category)->pluck('name')->all();

        return $inCategory->reject(fn (Product $product) => in_array($this->productSubCategory($product), $names, true))->values();
    }

    protected function mainCategories(): Collection
    {
        return MainCategory::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->orderBy('name')
            ->get();
    }

    protected function findMainCategory(int $id): ?MainCategory
    {
        return MainCategory::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->whereKey($id)
            ->first();
    }

    protected function subCategoriesOf(MainCategory $category): Collection
    {
        return AccessoryCategoryOption::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('main_category_id', $category->id)
            ->orderBy('name')
            ->get();
    }

    protected function findSubCategory(int $id): ?AccessoryCategoryOption
    {
        return AccessoryCategoryOption::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->whereKey($id)
            ->first();
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
            ->get();

        // A search naming one exact variant goes straight to it; a group's several matches share one row.
        $entries = $this->oneEntryPerGroup($matches)->take(self::SEARCH_LIMIT)->values();

        if ($entries->count() === 1 && $matches->count() === 1) {
            $this->sendProduct($entries->first()->id);

            return;
        }

        if ($entries->count() === 1 && $entries->first()->product_group_id !== null) {
            $this->sendVariants($entries->first()->product_group_id);

            return;
        }

        $matches = $entries;

        if ($matches->isEmpty()) {
            $this->client->sendText($this->contact->wa_id, $this->t('search_no_match', ['query' => mb_substr($query, 0, 40)]));
            $this->browse();

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
            $this->browse();

            return;
        }

        if ($product->stock_quantity <= 0) {
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

    protected function addToCart(int $productId, int|string $quantity = 1): void
    {
        $product = $this->findListedProduct($productId);

        if ($product === null || $product->stock_quantity <= 0) {
            $this->client->sendText($this->contact->wa_id, $this->t('product_unavailable'));

            return;
        }

        if (! Quantity::fitsScale($quantity) || ! Quantity::isWhole($quantity)) {
            $this->client->sendText($this->contact->wa_id, $this->t('qty_whole'));

            return;
        }

        $quantity = Quantity::normalize($quantity);

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
            'unit' => $product->unit,
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
            fn (WhatsAppOrderItem $item) => "• {$item->unit->amount($item->quantity)} × {$item->product_name} — Rs ".CustomerNotifications::money($item->lineTotal())
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

        $this->discardSlip($order);
        $order->update(['delivery_name' => null, 'delivery_address' => null, 'delivery_area' => null, 'delivery_phone' => null, 'payment_choice' => null]);

        // Saved details are only ever shown for the customer to choose — never used silently.
        if ($this->contact->hasSavedDelivery()) {
            $this->setStep('reviewing_saved_details', [], ['details_typed']);
            $this->client->sendButtons(
                $this->contact->wa_id,
                $this->t('saved_details', ['details' => $this->detailsBlock($this->contact->delivery_name, $this->contact->delivery_phone, $this->contact->delivery_address, $this->contact->delivery_area)]),
                ['details:use' => $this->t('btn_deliver_here'), 'ship:pickup' => $this->t('btn_pickup'), 'details:update' => $this->t('btn_update_details')],
            );

            return;
        }

        $this->askFulfilment();
    }

    protected function askFulfilment(): void
    {
        $this->setStep('awaiting_fulfilment', [], ['details_typed']);
        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('ask_fulfilment'),
            ['ship:delivery' => $this->t('btn_delivery'), 'ship:pickup' => $this->t('btn_pickup')],
        );
    }

    protected function chooseFulfilment(string $kind, bool $ask = true): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null) {
            $this->setStep('idle');
            $this->sendWelcome();

            return;
        }

        $fromSaved = ($this->contact->bot_state['step'] ?? null) === 'reviewing_saved_details';

        if ($kind === 'pickup') {
            // From the saved-details message, the name and phone it showed carry over.
            $order->update([
                'delivery_address' => $this->t('pickup_label'),
                'delivery_area' => null,
                'delivery_name' => $fromSaved ? $this->contact->delivery_name : null,
                'delivery_phone' => $fromSaved ? $this->contact->delivery_phone : null,
            ]);
        } else {
            $order->update(['delivery_name' => null, 'delivery_address' => null, 'delivery_area' => null, 'delivery_phone' => null]);
        }

        $this->remember(['details_typed' => ! $fromSaved, 'fulfilment' => $kind]);

        if ($ask) {
            $this->askNextDetail($order);
        }
    }

    protected function useSavedDetails(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || ! $this->contact->hasSavedDelivery()) {
            $this->startCheckout();

            return;
        }

        $order->update([
            'delivery_name' => $this->contact->delivery_name,
            'delivery_address' => $this->contact->delivery_address,
            'delivery_area' => $this->contact->delivery_area,
            'delivery_phone' => $this->contact->delivery_phone,
        ]);

        $this->remember(['details_typed' => false, 'fulfilment' => 'delivery']);
        $this->askNextDetail($order);
    }

    /**
     * Asks for the first missing detail — name, address, area, phone for a
     * delivery; name and phone for a pickup — or moves on once all are in.
     */
    protected function askNextDetail(WhatsAppOrder $order): void
    {
        $pickup = $order->delivery_address === $this->t('pickup_label');

        $missing = match (true) {
            blank($order->delivery_name) => 'name',
            ! $pickup && blank($order->delivery_address) => 'address',
            ! $pickup && blank($order->delivery_area) => 'area',
            blank($order->delivery_phone) => 'phone',
            default => null,
        };

        if ($missing === null) {
            $this->detailsComplete($order);

            return;
        }

        $this->setStep('awaiting_delivery_'.$missing);

        if ($missing === 'phone') {
            $this->client->sendButtons($this->contact->wa_id, $this->t('ask_phone'), ['phone:whatsapp' => $this->t('btn_use_this_number')]);

            return;
        }

        $this->client->sendText($this->contact->wa_id, $this->t('ask_'.$missing));
    }

    /**
     * Free text during checkout. At the delivery/pickup question (or the
     * saved-details one) "pickup" picks pickup and anything else is taken
     * as a typed delivery address, as the old single-step prompt allowed.
     */
    protected function captureCheckoutText(string $step, string $text): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null) {
            $this->setStep('idle');
            $this->sendWelcome();

            return;
        }

        if (in_array($step, ['awaiting_fulfilment', 'reviewing_saved_details'], true)) {
            if (strtolower($text) === 'pickup') {
                $this->chooseFulfilment('pickup');

                return;
            }

            $this->chooseFulfilment('delivery', ask: false);
            $order->refresh();
            $step = 'awaiting_delivery_address';
        }

        $field = substr($step, strlen('awaiting_delivery_'));
        $value = trim($text);

        [$valid, $value] = match ($field) {
            'name' => [mb_strlen($value) >= 2, mb_substr($value, 0, 120)],
            'address' => [mb_strlen($value) >= 5, mb_substr($value, 0, 500)],
            'area' => [mb_strlen($value) >= 2, mb_substr($value, 0, 120)],
            'phone' => [PhoneNumber::normalize($value) !== null && strlen(preg_replace('/\D+/', '', $value)) <= 15, mb_substr($value, 0, 20)],
            default => [false, $value],
        };

        if (! $valid) {
            $this->client->sendText($this->contact->wa_id, $this->t('invalid_'.$field));

            return;
        }

        $order->update(['delivery_'.$field => $value]);
        $this->remember(['details_typed' => true]);
        $this->askNextDetail($order);
    }

    protected function useWhatsAppNumber(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || ($this->contact->bot_state['step'] ?? null) !== 'awaiting_delivery_phone') {
            $this->startCheckout();

            return;
        }

        $order->update(['delivery_phone' => '+'.$this->contact->wa_id]);
        $this->remember(['details_typed' => true]);
        $this->askNextDetail($order);
    }

    /**
     * Typed details are only kept for next time if the customer says so.
     */
    protected function detailsComplete(WhatsAppOrder $order): void
    {
        $typed = (bool) ($this->contact->bot_state['details_typed'] ?? true);

        if ($typed) {
            $this->setStep('awaiting_save_details');
            $this->client->sendButtons(
                $this->contact->wa_id,
                $this->t($this->contact->hasSavedDelivery() ? 'ask_replace_saved' : 'ask_save_details'),
                ['save:yes' => $this->t('btn_save_details'), 'save:no' => $this->t('btn_dont_save')],
            );

            return;
        }

        $this->askPayment($order);
    }

    protected function answerSaveDetails(bool $save): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || ($this->contact->bot_state['step'] ?? null) !== 'awaiting_save_details') {
            $this->startCheckout();

            return;
        }

        if ($save) {
            $pickup = $order->delivery_address === $this->t('pickup_label');

            // A pickup has no address to keep, so any saved address stays as it was.
            $this->contact->fill(array_merge(
                ['delivery_name' => $order->delivery_name, 'delivery_phone' => $order->delivery_phone],
                $pickup ? [] : ['delivery_address' => $order->delivery_address, 'delivery_area' => $order->delivery_area],
            ))->save();

            $this->contact->syncDeliveryToCustomer();
            $this->client->sendText($this->contact->wa_id, $this->t('details_saved'));
        }

        $this->askPayment($order);
    }

    /**
     * Cash (on delivery, or at the shop for a pickup) or Online. Online is
     * only offered when the shop has an account with a number to pay into;
     * otherwise cash is the only way and the question is skipped.
     */
    protected function askPayment(WhatsAppOrder $order): void
    {
        $cash = $order->isPickup() ? WhatsAppPaymentChoice::PayAtShop : WhatsAppPaymentChoice::CashOnDelivery;

        if ($this->onlineAccounts()->isEmpty()) {
            $order->update(['payment_choice' => $cash]);
            $this->sendConfirmPrompt($order);

            return;
        }

        $this->setStep('awaiting_payment_choice');
        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('ask_payment'),
            ['pay:cash' => $this->t($cash === WhatsAppPaymentChoice::PayAtShop ? 'btn_pay_at_shop' : 'btn_cod'), 'pay:online' => $this->t('btn_pay_online')],
        );
    }

    protected function choosePayment(bool $online): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || ($this->contact->bot_state['step'] ?? null) !== 'awaiting_payment_choice') {
            $this->startCheckout();

            return;
        }

        $accounts = $this->onlineAccounts();

        if ($online && $accounts->isEmpty()) {
            $this->askPayment($order);

            return;
        }

        $order->update(['payment_choice' => $online
            ? WhatsAppPaymentChoice::Online
            : ($order->isPickup() ? WhatsAppPaymentChoice::PayAtShop : WhatsAppPaymentChoice::CashOnDelivery)]);

        if ($online) {
            $this->client->sendText($this->contact->wa_id, $this->t('online_accounts', [
                'total' => CustomerNotifications::money((float) $order->quoted_total),
                'accounts' => $accounts->map(fn (ShopAccount $account) => collect([
                    '*'.$account->name.'* ('.$account->provider_type.')',
                    filled($account->account_title) ? $this->t('account_title_line', ['value' => $account->account_title]) : null,
                    $this->t('account_number_line', ['value' => $account->account_number]),
                ])->filter()->implode("\n"))->implode("\n\n"),
            ]));

            $this->askSlip();

            return;
        }

        $this->sendConfirmPrompt($order);
    }

    protected function askSlip(): void
    {
        $this->setStep('awaiting_payment_slip');
        $this->client->sendButtons($this->contact->wa_id, $this->t('ask_slip'), ['slip:later' => $this->t('btn_send_later')]);
    }

    protected function sendSlipLater(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || ($this->contact->bot_state['step'] ?? null) !== 'awaiting_payment_slip') {
            $this->startCheckout();

            return;
        }

        $this->client->sendText($this->contact->wa_id, $this->t('slip_later_note'));
        $this->sendConfirmPrompt($order);
    }

    /**
     * The order a payment slip belongs to right now: the cart being checked
     * out online (at the slip question or the confirm step), or else this
     * contact's latest online order still waiting for the shop's decision.
     */
    protected function orderAwaitingSlip(): ?WhatsAppOrder
    {
        $collecting = $this->collectingOrder(false);

        if ($collecting !== null
            && $collecting->payment_choice === WhatsAppPaymentChoice::Online
            && in_array($this->contact->bot_state['step'] ?? null, ['awaiting_payment_slip', 'confirming'], true)) {
            return $collecting;
        }

        return WhatsAppOrder::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->where('whatsapp_contact_id', $this->contact->id)
            ->where('status', WhatsAppOrderStatus::Pending->value)
            ->where('payment_choice', WhatsAppPaymentChoice::Online->value)
            ->latest('submitted_at')
            ->latest('id')
            ->first();
    }

    /**
     * An image or document. Only a payment slip for an order that is waiting
     * for one is accepted; it is downloaded with the shop's token and kept
     * on the private disk, replacing any earlier slip for that order.
     */
    protected function handleMedia(array $message): void
    {
        $order = $this->orderAwaitingSlip();

        if ($order === null) {
            $this->client->sendText($this->contact->wa_id, $this->t('not_expecting_media'));

            return;
        }

        $media = $message[$message['type']] ?? [];
        $mime = strtolower((string) ($media['mime_type'] ?? ''));

        if (empty($media['id']) || ! isset(self::SLIP_TYPES[$mime])) {
            $this->client->sendText($this->contact->wa_id, $this->t('slip_wrong_type'));

            return;
        }

        $info = $this->client->mediaInfo((string) $media['id']);
        $mime = strtolower((string) ($info['mime_type'] ?? $mime));

        if (! isset(self::SLIP_TYPES[$mime])) {
            $this->client->sendText($this->contact->wa_id, $this->t('slip_wrong_type'));

            return;
        }

        if (($info['file_size'] ?? 0) > self::SLIP_MAX_BYTES) {
            $this->client->sendText($this->contact->wa_id, $this->t('slip_too_large'));

            return;
        }

        $contents = $this->client->downloadMedia($info['url']);

        if ($contents === '' || strlen($contents) > self::SLIP_MAX_BYTES) {
            $this->client->sendText($this->contact->wa_id, $this->t($contents === '' ? 'slip_wrong_type' : 'slip_too_large'));

            return;
        }

        $path = 'whatsapp-slips/'.$this->shop->id.'/'.$order->id.'-'.Str::random(20).'.'.self::SLIP_TYPES[$mime];
        Storage::disk(self::SLIP_DISK)->put($path, $contents);

        $previous = $order->payment_slip_path;
        $order->update(['payment_slip_path' => $path]);

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::SLIP_DISK)->delete($previous);
        }

        if ($order->status === WhatsAppOrderStatus::Collecting) {
            $this->client->sendText($this->contact->wa_id, $this->t('slip_received'));
            $this->sendConfirmPrompt($order);

            return;
        }

        $this->client->sendText($this->contact->wa_id, $this->t('slip_attached', ['order' => $order->orderNumber()]));
    }

    protected function discardSlip(WhatsAppOrder $order): void
    {
        if ($order->payment_slip_path !== null) {
            Storage::disk(self::SLIP_DISK)->delete($order->payment_slip_path);
            $order->update(['payment_slip_path' => null]);
        }
    }

    /**
     * The shop's own wording for a customisable message (tokens like {shop}),
     * falling back to the standard text for the customer's language.
     */
    protected function shopText(string $key, array $tokens, array $replace): string
    {
        $custom = $this->shop->whatsappMessage($key, WhatsAppLanguage::for($this->contact));

        return $custom !== null ? strtr($custom, $tokens) : $this->t($key, $replace);
    }

    protected function onlineAccounts(): Collection
    {
        return ShopAccount::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $this->shop->id)
            ->whereNotNull('account_number')
            ->where('account_number', '!=', '')
            ->orderBy('name')
            ->get();
    }

    protected function sendConfirmPrompt(WhatsAppOrder $order): void
    {
        $this->setStep('confirming');

        $this->client->sendButtons(
            $this->contact->wa_id,
            $this->t('confirm_prompt', ['summary' => $this->cartSummary($order), 'details' => $this->orderDetailsBlock($order)]),
            ['confirm' => $this->t('btn_confirm'), 'clear' => $this->t('btn_cancel')],
        );
    }

    protected function orderDetailsBlock(WhatsAppOrder $order): string
    {
        $block = $this->detailsBlock($order->delivery_name, $order->delivery_phone, $order->delivery_address, $order->delivery_area);

        if ($order->payment_choice === null) {
            return $block;
        }

        $payment = $this->t('payment_'.$order->payment_choice->value);

        if ($order->payment_choice === WhatsAppPaymentChoice::Online && $order->payment_slip_path !== null) {
            $payment .= ' '.$this->t('slip_tag');
        }

        return $block."\n".$this->t('detail_payment', ['value' => $payment]);
    }

    protected function detailsBlock(?string $name, ?string $phone, ?string $address, ?string $area): string
    {
        $where = $address === $this->t('pickup_label') || blank($address)
            ? (string) $address
            : collect([$address, $area])->filter()->implode(', ');

        return collect([
            filled($name) ? $this->t('detail_name', ['value' => $name]) : null,
            filled($phone) ? $this->t('detail_phone', ['value' => $phone]) : null,
            filled($where) ? $this->t('detail_delivery', ['value' => $where]) : null,
        ])->filter()->implode("\n");
    }

    protected function confirmOrder(): void
    {
        $order = $this->collectingOrder(false);

        if ($order === null || $this->orderItems($order)->isEmpty()) {
            $this->client->sendButtons($this->contact->wa_id, $this->t('cart_empty'), ['browse' => $this->t('btn_browse')]);

            return;
        }

        // A Confirm tapped before delivery or pickup was chosen restarts checkout.
        // Name and phone fall back to what WhatsApp already tells us.
        if (blank($order->delivery_address)) {
            $this->startCheckout();

            return;
        }

        // Over the plan's monthly orders: the cart stays as it is and a person
        // at the shop takes the conversation from here.
        try {
            app(Entitlements::class)->ensureCanAdd($this->shop, LimitKey::WhatsAppOrdersPerMonth);
        } catch (UsageLimitReached) {
            $this->remember(['handoff_until' => now()->addHours(self::HANDOFF_HOURS)->toIso8601String()]);
            $this->client->sendText($this->contact->wa_id, $this->t('order_limit', ['shop' => $this->shop->invoiceDisplayName()]));

            return;
        }

        $state = $this->contact->bot_state ?? [];

        $order->update([
            'status' => WhatsAppOrderStatus::Pending,
            'submitted_at' => now(),
            'customer_id' => $order->customer_id ?? $this->contact->customer_id,
            'delivery_name' => $order->delivery_name ?? mb_substr((string) ($this->contact->customer?->name ?? $this->contact->profile_name), 0, 120) ?: null,
            'delivery_phone' => $order->delivery_phone ?? '+'.$this->contact->wa_id,
            'origin_ref' => $state['origin_ref'] ?? null,
            'referral' => $state['referral'] ?? null,
        ]);

        $this->setStep('idle', [], ['origin_ref', 'referral', 'details_typed', 'fulfilment']);

        $this->client->sendText($this->contact->wa_id, $this->shopText(
            'order_received',
            ['{order}' => $order->orderNumber(), '{shop}' => $this->shop->invoiceDisplayName()],
            ['order' => $order->orderNumber()],
        ));
    }

    protected function clearCart(): void
    {
        $order = $this->collectingOrder(false);

        if ($order !== null) {
            $slip = $order->payment_slip_path;

            DB::transaction(function () use ($order) {
                WhatsAppOrderItem::withoutGlobalScope(ShopScope::class)->where('whatsapp_order_id', $order->id)->delete();
                $order->delete();
            });

            if ($slip !== null) {
                Storage::disk(self::SLIP_DISK)->delete($slip);
            }
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
