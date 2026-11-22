<?php

namespace Tests\Feature;

use App\Support\SiteFeatures;
use App\Support\SiteIndustries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFeaturePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feature_pages_exist_in_the_agreed_order_and_unknown_slugs_404(): void
    {
        $this->assertSame(
            ['pos', 'returns-exchange', 'stock', 'purchase-orders', 'udhaar', 'cash-balances', 'reports', 'double-entry-ledger', 'payroll', 'multi-branch', 'online-store', 'courier-booking', 'whatsapp-ordering', 'social-posting', 'meta-ads', 'mobile-shop-services'],
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

    public function test_the_industry_pages_exist_with_their_items_and_link_back_to_industries(): void
    {
        $this->assertSame(['pharmacy', 'kiryana-store', 'clothing-shoes'], SiteIndustries::slugs());

        foreach (SiteIndustries::all() as $slug => $page) {
            $response = $this->get('/industries/'.$slug)->assertOk();
            $response->assertSee($page['title']);
            foreach ($page['items'] as [, $title]) {
                $response->assertSee($title);
            }
            $response->assertSee('All industries');
            $response->assertSee('href="'.route('site.industries').'"', false);
            foreach ($page['related'] as $related) {
                $this->assertNotNull(SiteFeatures::find($related), "$slug relates to unknown feature $related");
                $response->assertSee('href="'.route('site.feature', $related).'"', false);
            }
        }

        // Clothing leans on variants and measured items, which are off until switched on, so the page says so.
        $this->get('/industries/clothing-shoes')->assertSee('role="note"', false)->assertSee('free features we switch on for your shop');

        $this->get('/industries/bakery')->assertNotFound();
        $this->get('/industries/wholesale')->assertNotFound();
    }

    public function test_industries_menu_is_separate_from_features_and_lists_only_shop_types(): void
    {
        $industryUrls = array_column(SiteIndustries::menu(), 'url');
        $this->assertSame(route('site.feature', 'mobile-shop-services'), $industryUrls[0]);
        $this->assertCount(4, $industryUrls);

        foreach (['/', '/pricing', '/industries', '/industries/pharmacy', '/features/udhaar'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('id="site-industries-menu"', $html, $path);
            $this->assertStringContainsString('id="site-mobile-industries"', $html, $path);
            $this->assertStringContainsString('aria-label="Show all industries"', $html, $path);

            // The desktop Features dropdown holds capability pages only.
            $features = str($html)->after('id="site-features-menu"')->before('id="site-industries-menu"')->toString();
            foreach (SiteIndustries::slugs() as $slug) {
                $this->assertStringNotContainsString(route('site.industry', $slug), $features, "$path: $slug in the Features menu");
            }

            $industries = str($html)->after('id="site-industries-menu"')->before('</nav>')->toString();
            foreach ($industryUrls as $url) {
                $this->assertStringContainsString('href="'.$url.'"', $industries, "$path → $url");
            }
        }

        $activePill = '#<div class="flex items-center rounded-full transition-colors bg-brand-50 text-brand-700">\s*<a href="'.preg_quote(route('site.industries'), '#').'"#';
        $this->assertMatchesRegularExpression($activePill, $this->get('/industries/kiryana-store')->getContent());
        $this->assertDoesNotMatchRegularExpression($activePill, $this->get('/features/pos')->getContent());
    }

    public function test_the_industries_overview_links_every_shop_type_and_no_unbuilt_group(): void
    {
        $response = $this->get('/industries')->assertOk();

        foreach (SiteIndustries::menu() as $industry) {
            $response->assertSee('href="'.$industry['url'].'"', false);
            $response->assertSee($industry['summary']);
        }

        $text = strtolower(strip_tags($response->getContent()));
        foreach (['bakery', 'bakeries', 'restaurant', 'school'] as $unbuilt) {
            $this->assertStringNotContainsString($unbuilt, $text);
        }
    }

    public function test_no_page_advertises_bakeries_or_the_old_clothing_name_and_reminders_no_longer_deny_links(): void
    {
        foreach (['/', '/features', '/industries', '/pricing', '/about'] as $path) {
            $text = strtolower(strip_tags($this->get($path)->getContent()));
            $this->assertStringNotContainsString('bakeries', $text, $path);
            $this->assertStringNotContainsString('clothing & garments', html_entity_decode($text), $path);
        }

        $this->get('/features/udhaar')->assertDontSee('no payment links')->assertSee('links to the customer’s own account page');
    }

    public function test_the_pricing_faq_says_which_plans_include_purchase_orders_and_payroll_from_plan_data(): void
    {
        $plans = \App\Models\Plan::active()->ordered()->get();
        $this->assertGreaterThanOrEqual(2, $plans->count());

        foreach ($plans as $i => $plan) {
            $plan->update(['feature_flags' => ['purchase_orders' => true, 'payroll' => $i > 0] + ($plan->feature_flags ?? [])]);
        }
        $withPayroll = $plans->slice(1)->pluck('name')->join(', ', ' and ');

        $this->get('/pricing')->assertOk()
            ->assertSee('Purchase orders are included in every plan.')
            ->assertSee('Payroll is included in '.$withPayroll.'.')
            ->assertSee('On a plan without one, add it as a monthly add-on')
            ->assertDontSee('Some plans include them');

        foreach ($plans as $plan) {
            $plan->update(['feature_flags' => ['purchase_orders' => true, 'payroll' => true] + ($plan->feature_flags ?? [])]);
        }

        $this->get('/pricing')
            ->assertSee('Purchase orders are included in every plan. Payroll is included in every plan.')
            ->assertDontSee('On a plan without one');
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
