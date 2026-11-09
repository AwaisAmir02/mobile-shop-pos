<?php

namespace App\Support;

use App\Enums\FeatureKey;
use App\Models\User;

/**
 * D14: the per-screen guide behind the header "?" button.
 *
 * One PHP file per screen, named after the route, in each language folder:
 * resources/guides/en/sales.index.php and resources/guides/ur/sales.index.php
 * (Roman Urdu). Both files describe the same screen with the same section ids,
 * which GuideTest checks.
 *
 * A file returns:
 *   'title'    => the screen's name as the user knows it,
 *   'summary'  => one or two sentences on what the screen is for,
 *   'sections' => [
 *       [
 *           'id'      => 'checkout',            // stable, same in both languages
 *           'heading' => 'Taking payment',
 *           'intro'   => '…',                   // optional paragraph
 *           'steps'   => ['…', ['text' => '…', 'feature' => 'variants']], // optional numbered how-to
 *           'items'   => [                      // optional: a label exactly as on screen, and what it does
 *               ['label' => 'Complete Sale', 'text' => '…'],
 *           ],
 *       ],
 *   ],
 *
 * A section or an item can be limited to the people it applies to:
 *   'needs'   => 'sales-edit-cancel' (or a list; every one is required),
 *   'feature' => 'barcode'           (a FeatureKey value the shop must have on),
 *   'owner'   => true                (the shop owner only).
 */
final class Guide
{
    /** Language codes and the names on the toggle. Each one is a folder under resources/guides. */
    public const LANGUAGES = ['en' => 'English', 'ur' => 'Roman Urdu'];

    /** The language the panel opens in until the user picks one; the choice is remembered per device. */
    public const DEFAULT_LANGUAGE = 'ur';

    /** @var array<string, array|null> */
    private static array $files = [];

    public static function path(string $language, string $key): string
    {
        return resource_path("guides/{$language}/{$key}.php");
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && preg_match('/^[a-z0-9.-]+$/', $key) === 1 && is_file(self::path('en', $key));
    }

    /** Every screen with a guide, by route name. */
    public static function keys(): array
    {
        return collect(glob(resource_path('guides/en/*.php')) ?: [])
            ->map(fn (string $file) => basename($file, '.php'))
            ->sort()
            ->values()
            ->all();
    }

    /** The raw file, before anything is hidden for the user. */
    public static function load(string $key, string $language): ?array
    {
        if (! self::exists($key) || ! array_key_exists($language, self::LANGUAGES)) {
            return null;
        }

        $path = self::path($language, $key);

        return self::$files[$path] ??= is_file($path) ? require $path : null;
    }

    /**
     * The guide for a screen as this user should see it, in every language:
     * ['key' => ..., 'languages' => ['en' => [...], 'ur' => [...]]], or null when
     * the screen has no guide or the user isn't a shop user.
     */
    public static function forUser(?string $key, ?User $user): ?array
    {
        if ($user === null || $user->is_super_admin || ! self::exists($key)) {
            return null;
        }

        $languages = [];

        foreach (array_keys(self::LANGUAGES) as $language) {
            $guide = self::load($key, $language);

            if ($guide === null) {
                continue;
            }

            $guide['sections'] = collect($guide['sections'] ?? [])
                ->filter(fn (array $section) => self::visibleTo($section, $user))
                ->map(function (array $section) use ($user) {
                    $section['items'] = collect($section['items'] ?? [])
                        ->filter(fn (array $item) => self::visibleTo($item, $user))
                        ->values()
                        ->all();
                    $section['steps'] = collect($section['steps'] ?? [])
                        ->map(fn (string|array $step) => is_array($step) ? $step : ['text' => $step])
                        ->filter(fn (array $step) => self::visibleTo($step, $user))
                        ->pluck('text')
                        ->all();

                    return $section;
                })
                ->filter(fn (array $section) => filled($section['intro'] ?? null) || ! empty($section['steps']) || ! empty($section['items']))
                ->values()
                ->all();

            $languages[$language] = $guide;
        }

        return $languages === [] ? null : ['key' => $key, 'languages' => $languages];
    }

    private static function visibleTo(array $node, User $user): bool
    {
        if (($node['owner'] ?? false) && ! $user->is_owner) {
            return false;
        }

        foreach ((array) ($node['needs'] ?? []) as $screen) {
            if (! $user->hasAccessTo($screen)) {
                return false;
            }
        }

        if (isset($node['feature'])) {
            $feature = FeatureKey::from($node['feature']);

            if (! $user->shop?->{$feature->column()}) {
                return false;
            }
        }

        return true;
    }
}
