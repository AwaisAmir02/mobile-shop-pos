<?php

namespace Tests\Feature;

use App\Support\SiteFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFeaturePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feature_pages_exist_in_the_agreed_order_and_unknown_slugs_404(): void
    {
        $this->assertSame(
            ['pos', 'stock', 'purchase-orders', 'udhaar', 'cash-balances', 'reports', 'payroll', 'multi-branch', 'whatsapp-ordering', 'social-posting', 'meta-ads', 'mobile-shop-services'],
            SiteFeatures::slugs(),
        );

        foreach (SiteFeatures::all() as $slug => $feature) {
            $response = $this->get('/features/'.$slug)->assertOk();
            $response->assertSee($feature['title']);
            foreach ($feature['items'] as [, $title]) {
                $response->assertSee($title);
            }
            $response->assertSee('Related features');
            foreach ($feature['related'] as $related) {
                $response->assertSee('href="'.route('site.feature', $related).'"', false);
            }
        }

        $this->get('/features/party-ledger')->assertNotFound();
        $this->get('/features/nope')->assertNotFound();
    }

    public function test_every_site_page_has_the_features_dropdown_and_mobile_list(): void
    {
        foreach (['/', '/features', '/pricing', '/about', '/contact', '/features/udhaar'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('id="site-features-menu"', $html, $path);
            $this->assertStringContainsString('id="site-mobile-features"', $html, $path);
            $this->assertStringContainsString('aria-label="Show all features"', $html, $path);

            foreach (SiteFeatures::slugs() as $slug) {
                // Once in the desktop dropdown and once in the mobile list.
                $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.route('site.feature', $slug).'"'), "$path → $slug");
            }
        }
    }

    public function test_features_is_highlighted_on_the_overview_and_every_feature_page(): void
    {
        $activePill = '#<div class="flex items-center rounded-full transition-colors bg-brand-50 text-brand-700"#';

        foreach (['/features', '/features/meta-ads'] as $path) {
            $this->assertMatchesRegularExpression($activePill, $this->get($path)->getContent(), $path);
        }

        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('site.feature', 'meta-ads'), '#').'" class="[^"]*bg-brand-50"\s+aria-current="page"#',
            $this->get('/features/meta-ads')->getContent(),
        );

        $this->assertDoesNotMatchRegularExpression($activePill, $this->get('/pricing')->getContent());
    }

    public function test_the_overview_links_to_every_page_and_keeps_the_old_anchors(): void
    {
        $response = $this->get('/features')->assertOk();

        foreach (SiteFeatures::all() as $slug => $feature) {
            $response->assertSee('id="'.$feature['anchor'].'" href="'.route('site.feature', $slug).'"', false);
            $response->assertSee($feature['summary']);
        }

        foreach (['counter', 'stock', 'udhaar', 'services', 'whatsapp', 'marketing', 'control'] as $oldAnchor) {
            $response->assertSee('id="'.$oldAnchor.'"', false);
        }

        $response->assertSee('Your shop, your rules');
    }

    public function test_party_ledger_stays_a_one_liner_on_stock_and_udhaar_and_no_unreleased_whatsapp_features_appear(): void
    {
        foreach (['/features/stock', '/features/udhaar'] as $path) {
            $this->get($path)->assertSee('Know what you owe each supplier and what each party owes you.');
        }

        $text = strtolower(strip_tags($this->get('/features/whatsapp-ordering')->getContent()));
        foreach (['cash on delivery', 'payment screenshot', 'sub-categor', 'delivery address', 'welcome message'] as $unreleased) {
            $this->assertStringNotContainsString($unreleased, $text);
        }
    }

    public function test_every_feature_line_comes_from_the_original_site_copy(): void
    {
        // The original single Features page and the home page, as they were before the split.
        $original = file_get_contents(base_path('tests/Fixtures/site-copy-before-feature-split.txt'));

        foreach (array_diff_key(SiteFeatures::all(), array_flip(SiteFeatures::NEW_COPY)) as $slug => $feature) {
            foreach (array_merge([$feature['summary'], $feature['title'], $feature['lead'], $feature['eyebrow']], array_column($feature['items'], 1), array_column($feature['items'], 2), array_column($feature['spotlights'] ?? [], 0), array_column($feature['spotlights'] ?? [], 1)) as $line) {
                $this->assertStringContainsString($line, $original, "$slug: “{$line}” is not in the original copy");
            }
        }
    }
}
