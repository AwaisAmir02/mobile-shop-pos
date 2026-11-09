<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Works out which screen a support ticket is about from the address the
 * browser was on. The address comes from the user's browser, so it is only
 * trusted as far as it matches one of our own shop screens: anything else
 * (another site, the support pages themselves, a typo) is dropped.
 */
class SupportScreen
{
    /**
     * @return array{route: string, url: string, title: ?string}|null
     */
    public static function resolve(?string $address, ?string $documentTitle = null): ?array
    {
        if (! $address) {
            return null;
        }

        $parts = parse_url($address);

        if ($parts === false || isset($parts['host']) && $parts['host'] !== parse_url(config('app.url'), PHP_URL_HOST) && $parts['host'] !== request()->getHost()) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');
        $url = Str::limit($path.(isset($parts['query']) ? '?'.$parts['query'] : ''), 500, '');

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return null;
        }

        $name = $route->getName();

        if (! $name || Str::startsWith($name, ['support.', 'admin.', 'site.', 'login', 'password.', 'oauth.', 'webhooks.', 'livewire.', 'storage.'])) {
            return null;
        }

        return ['route' => $name, 'url' => $url, 'title' => self::cleanTitle($documentTitle)];
    }

    /** "Sales History — HisabOKitab" becomes "Sales History". */
    public static function cleanTitle(?string $title): ?string
    {
        $title = Str::squish((string) $title);
        $suffix = ' — '.config('app.name');

        if (Str::endsWith($title, $suffix)) {
            $title = Str::beforeLast($title, $suffix);
        }

        return $title === '' || $title === config('app.name') ? null : Str::limit($title, 150, '');
    }
}
