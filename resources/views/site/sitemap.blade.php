{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach (['home', 'site.features', 'site.pricing', 'site.about', 'site.contact'] as $name)
    <url>
        <loc>{{ route($name) }}</loc>
        <changefreq>weekly</changefreq>
        <priority>{{ $name === 'home' ? '1.0' : '0.8' }}</priority>
    </url>
@endforeach
</urlset>
