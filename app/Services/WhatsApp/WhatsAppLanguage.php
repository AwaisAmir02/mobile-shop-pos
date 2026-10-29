<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;

class WhatsAppLanguage
{
    public const SUPPORTED = ['en', 'ur'];

    protected const ROMAN_URDU_WORDS = [
        'salam', 'assalam', 'aoa', 'kya', 'kia', 'hai', 'hain', 'chahiye', 'chahye', 'kitne', 'kitna', 'kitny',
        'bhai', 'bhae', 'aap', 'ap', 'mujhe', 'mujhy', 'kar', 'karo', 'karein', 'kahan', 'kab', 'nahi', 'nahin',
        'acha', 'theek', 'shukriya', 'jee', 'ji', 'wala', 'wali', 'dena', 'lena', 'mein', 'hy', 'ha',
    ];

    public static function detect(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return 'ur';
        }

        $words = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $hits = count(array_intersect($words, self::ROMAN_URDU_WORDS));

        if ($hits >= 2 || ($hits === 1 && count($words) <= 3)) {
            return 'ur';
        }

        return count($words) >= 2 ? 'en' : null;
    }

    public static function for(?WhatsAppContact $contact): string
    {
        return in_array($contact?->language, self::SUPPORTED, true) ? $contact->language : 'en';
    }

    public static function t(?WhatsAppContact $contact, string $key, array $replace = []): string|array
    {
        return __('whatsapp.'.$key, $replace, self::for($contact));
    }
}
