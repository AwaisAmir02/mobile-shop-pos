@php
    $modified = fn (string ...$views) => date('Y-m-d', max(array_map(fn ($view) => @filemtime(resource_path('views/'.$view)) ?: 0, $views)));
    $layout = 'components/site/layout.blade.php';
    $pages = [
        ['home', 'site/home.blade.php', '1.0'],
        ['site.features', 'site/features.blade.php', '0.9'],
        ['site.pricing', 'site/pricing.blade.php', '0.9'],
        ['site.about', 'site/about.blade.php', '0.6'],
        ['site.contact', 'site/contact.blade.php', '0.6'],
        ['site.signup', 'site/signup.blade.php', '0.5'],
    ];
    $featuresModified = date('Y-m-d', max(@filemtime(app_path('Support/SiteFeatures.php')) ?: 0, @filemtime(resource_path('views/site/feature.blade.php')) ?: 0, @filemtime(resource_path('views/'.$layout)) ?: 0));
@endphp
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as [$name, $view, $priority])
    <url>
        <loc>{{ route($name) }}</loc>
        <lastmod>{{ $modified($view, $layout) }}</lastmod>
        <priority>{{ $priority }}</priority>
    </url>
@endforeach
@foreach (\App\Support\SiteFeatures::slugs() as $slug)
    <url>
        <loc>{{ route('site.feature', $slug) }}</loc>
        <lastmod>{{ $featuresModified }}</lastmod>
        <priority>0.8</priority>
    </url>
@endforeach
</urlset>
