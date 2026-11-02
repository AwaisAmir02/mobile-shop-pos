<?php

namespace App\Support;

/**
 * Ready-made wording a shop can pick for its two customisable WhatsApp
 * messages. {shop} becomes the shop's name and {order} the order number.
 */
class WhatsAppMessageSuggestions
{
    /** The messages a shop may customise, with the tokens each one supports. */
    public const MESSAGES = [
        'welcome' => ['{shop}'],
        'order_received' => ['{order}', '{shop}'],
    ];

    public const MAX_LENGTH = 900;

    /**
     * @return array<string, array<int, array{label: string, en: string, ur: string}>>
     */
    public static function all(): array
    {
        return [
            'welcome' => [
                [
                    'label' => 'Friendly',
                    'en' => "Assalam o Alaikum! Welcome to {shop} 😊\n\nTap below to browse our products and order right here, or just type what you're looking for.\nReply HELP to talk to us. Urdu ke liye URDU likhein.",
                    'ur' => "Assalam o Alaikum! {shop} mein khush aamdeed 😊\n\nNeeche tap kar ke products dekhein aur yahin order karein, ya jo chahiye uska naam likh dein.\nHum se baat karne ke liye HELP likhein. For English, type ENGLISH.",
                ],
                [
                    'label' => 'Short',
                    'en' => "Welcome to {shop}! Browse and order on WhatsApp, or type a product name to search. Reply HELP for the shop.",
                    'ur' => "{shop} mein khush aamdeed! WhatsApp par products dekhein aur order karein, ya product ka naam likh kar dhoondein. Shop ke liye HELP likhein.",
                ],
                [
                    'label' => 'Delivery & pickup',
                    'en' => "Assalam o Alaikum from {shop}! 📱\n\nPhones and accessories with home delivery or pickup from the shop. Browse below, add to your cart and we'll confirm your order here.\nReply HELP anytime to talk to us.",
                    'ur' => "{shop} ki taraf se Assalam o Alaikum! 📱\n\nMobiles aur accessories, ghar par delivery ya shop se pickup. Neeche products dekhein, cart mein daalein, hum order yahin confirm karein ge.\nBaat karne ke liye kabhi bhi HELP likhein.",
                ],
            ],
            'order_received' => [
                [
                    'label' => 'Friendly',
                    'en' => "Thank you! 🙏 Your order {order} has been received.\nWe'll confirm it shortly and send your invoice right here.",
                    'ur' => "Shukriya! 🙏 Aapka order {order} mil gaya hai.\nHum jald confirm kar ke invoice yahin bhej dein ge.",
                ],
                [
                    'label' => 'Short',
                    'en' => "Order {order} received. We'll confirm it soon.",
                    'ur' => "Order {order} mil gaya. Hum jald confirm karein ge.",
                ],
                [
                    'label' => 'With next steps',
                    'en' => "Thank you for shopping with {shop}! Your order {order} is in.\n\nWe're checking stock now and will message you to confirm, with the invoice. For delivery orders we'll share the expected time.",
                    'ur' => "{shop} se khareedari ka shukriya! Aapka order {order} mil gaya hai.\n\nHum stock check kar rahe hain aur invoice ke saath confirm kar ke message karein ge. Delivery orders ke liye andaazan waqt bhi batayein ge.",
                ],
            ],
        ];
    }
}
