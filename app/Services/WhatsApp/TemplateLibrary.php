<?php

namespace App\Services\WhatsApp;

class TemplateLibrary
{
    public const VERSION = 'v1';

    public static function definitions(): array
    {
        return [
            'order_update' => [
                'category' => 'UTILITY',
                'body' => [
                    'en' => 'Update on your order {{1}} from {{2}}: {{3}}',
                    'ur' => '{{2}} se aapke order {{1}} ki update: {{3}}',
                ],
                'examples' => ['WA-00012', 'Ali Mobiles', 'Your order is out for delivery.'],
            ],
            'repair_ready' => [
                'category' => 'UTILITY',
                'body' => [
                    'en' => 'Hi {{1}}, your {{2}} repair ({{3}}) at {{4}} is ready for pickup. {{5}}',
                    'ur' => 'Assalam o Alaikum {{1}}, {{4}} par aapka {{2}} repair ({{3}}) tayyar hai, aa kar le jayein. {{5}}',
                ],
                'examples' => ['Ahmed', 'Mobile', 'REP-00041', 'Ali Mobiles', 'Amount remaining: Rs 1,200.'],
            ],
            'payment_reminder' => [
                'category' => 'UTILITY',
                'body' => [
                    'en' => "Hi {{1}}, a friendly reminder from {{2}} about your pending balance: {{3}}.\n\nTotal due: Rs {{4}}\n\nIf you have already paid, please ignore this message. Thank you!",
                    'ur' => "Assalam o Alaikum {{1}}, {{2}} ki taraf se aapke baaki hisaab ki yaad dehani: {{3}}.\n\nKul baaki: Rs {{4}}\n\nAgar pehle hi ada kar chuke hain to is message ko nazar andaz karein. Shukriya!",
                ],
                'examples' => ['Bilal', 'Ali Mobiles', 'You bought iPhone 13 (INV-00012) - Rs 5,000 remaining; Udhaar - Rs 2,000 remaining', '7,000'],
            ],
            'restock_alert' => [
                'category' => 'UTILITY',
                'body' => [
                    'en' => 'Good news! {{1}} is back in stock at {{2}} for Rs {{3}}. Reply HI to order on WhatsApp.',
                    'ur' => 'Khushkhabri! {{1}} {{2}} par wapas stock mein aa gaya hai, Rs {{3}}. Order ke liye HI likhein.',
                ],
                'examples' => ['Samsung 25W Charger', 'Ali Mobiles', '2,500'],
            ],
            'review_request' => [
                'category' => 'UTILITY',
                'body' => [
                    'en' => 'Hi {{1}}, thank you for your order {{2}} from {{3}}! How was your experience?',
                    'ur' => 'Assalam o Alaikum {{1}}, {{3}} se order {{2}} karne ka shukriya! Aapka tajurba kaisa raha?',
                ],
                'examples' => ['Sana', 'WA-00012', 'Ali Mobiles'],
                'buttons' => [
                    'en' => ['Excellent', 'Good', 'Not happy'],
                    'ur' => ['Zabardast', 'Acha', 'Khush nahi'],
                ],
                'button_payloads' => ['rate:5', 'rate:4', 'rate:2'],
            ],
        ];
    }

    public static function name(string $key): string
    {
        return 'hok_'.$key.'_'.self::VERSION;
    }

    public static function definition(string $key): array
    {
        return self::definitions()[$key] ?? throw new \InvalidArgumentException("Unknown template {$key}");
    }

    public static function createPayload(string $key, string $language): array
    {
        $definition = self::definition($key);

        $components = [[
            'type' => 'BODY',
            'text' => $definition['body'][$language],
            'example' => ['body_text' => [$definition['examples']]],
        ]];

        if (isset($definition['buttons'][$language])) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(fn (string $text) => ['type' => 'QUICK_REPLY', 'text' => $text], $definition['buttons'][$language]),
            ];
        }

        return [
            'name' => self::name($key),
            'language' => $language,
            'category' => $definition['category'],
            'components' => $components,
        ];
    }

    public static function sanitizeParam(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);
        $value = preg_replace('/ {4,}/', '   ', $value);

        return mb_substr(trim($value) !== '' ? trim($value) : '-', 0, 1000);
    }
}
