<?php

namespace Tests\Feature;

use App\Enums\BusinessCategory;
use App\Enums\BusinessGroup;
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
            ['pos', 'returns-exchange', 'stock', 'purchase-orders', 'udhaar', 'cash-balances', 'reports', 'double-entry-ledger', 'payroll', 'multi-branch', 'online-store', 'courier-booking', 'whatsapp-ordering', 'social-posting', 'meta-ads'],
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

    public function test_every_business_group_and_business_type_has_its_page(): void
    {
        $this->assertSame(['retail-shops', 'wholesale-distribution', 'online-sellers', 'service-businesses'], SiteIndustries::groupSlugs());
        $this->assertSame(
            ['mobile-shop', 'pharmacy', 'kiryana-store', 'clothing-shoes', 'wholesaler', 'trader', 'distributor', 'social-seller', 'daraz-seller', 'online-brand', 'repair-workshop', 'salon', 'spa', 'gym', 'personal-training'],
            SiteIndustries::pageSlugs(),
        );
        $this->assertSame([...SiteIndustries::groupSlugs(), ...SiteIndustries::pageSlugs()], SiteIndustries::slugs());

        // One overview per business group, in the enum's order.
        $groupSlugs = collect(SiteIndustries::groups())->mapWithKeys(fn (array $group, string $slug) => [$group['group']->value => $slug]);
        $this->assertSame(array_map(fn (BusinessGroup $group) => $group->value, BusinessGroup::cases()), $groupSlugs->keys()->all());

        // One page per signup business type except "Other shop", filed under that type's own group.
        $covered = [];
        foreach (SiteIndustries::all() as $slug => $page) {
            $this->assertSame($groupSlugs[$page['category']->group()->value], $page['group'], $slug);
            $covered[] = $page['category']->value;
        }
        $signup = array_diff(BusinessGroup::signupValues(), [BusinessCategory::Other->value]);
        $this->assertEqualsCanonicalizing(array_values($signup), $covered);
        $this->assertSame(array_unique($covered), $covered);
    }

    public function test_trades_run_together_link_each_other_both_ways(): void
    {
        foreach (SiteIndustries::all() as $slug => $page) {
            $partner = SiteIndustries::partnerOf($slug);
            $this->assertSame($page['category']->combinesWith() !== [], $partner !== null, $slug);
            $this->assertSame($partner !== null, isset($page['pair']), "$slug has pair copy only when it has a partner");
            if ($partner !== null) {
                $this->assertSame($slug, SiteIndustries::partnerOf($partner));
                $this->get('/industries/'.$slug)->assertSee($page['pair'][0])->assertSee('href="'.route('site.industry', $partner).'"', false);
            }
        }

        $this->assertSame('spa', SiteIndustries::partnerOf('salon'));
        $this->assertSame('personal-training', SiteIndustries::partnerOf('gym'));
        $this->get('/industries/service-businesses')->assertSee('Runs together with Spas')->assertSee('Runs together with Gyms');
    }

    public function test_each_business_type_page_shows_its_features_under_its_group(): void
    {
        foreach (SiteIndustries::all() as $slug => $page) {
            $group = SiteIndustries::findGroup($page['group']);
            $response = $this->get('/industries/'.$slug)->assertOk();
            $response->assertSee($page['title']);
            foreach ($page['items'] as [, $title]) {
                $response->assertSee($title);
            }
            $response->assertSee('href="'.route('site.industry', $page['group']).'"', false);
            $response->assertSee('All industries');
            $response->assertSee('href="'.route('site.industries').'"', false);
            foreach ($page['related'] as $related) {
                $this->assertNotNull(SiteFeatures::find($related), "$slug relates to unknown feature $related");
                $response->assertSee('href="'.route('site.feature', $related).'"', false);
            }
            foreach (array_keys(SiteIndustries::pagesIn($page['group'])) as $sibling) {
                $response->assertSee('href="'.route('site.industry', $sibling).'"', false);
            }

            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $json);
            $breadcrumbs = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'BreadcrumbList');
            $this->assertSame(['Home', 'Industries', $group['name'], $page['name']], array_column($breadcrumbs['itemListElement'], 'name'), $slug);
        }

        // Clothing leans on variants and measured items, which are off until switched on, so the page says so.
        $this->get('/industries/clothing-shoes')->assertSee('role="note"', false)->assertSee('free features we switch on for your shop');

        foreach (['bakery', 'wholesale', 'clinic', 'mobile-shop-services', 'nope'] as $unknown) {
            $this->get('/industries/'.$unknown)->assertNotFound();
        }
    }

    public function test_each_group_page_lists_its_business_types_and_what_they_all_get(): void
    {
        foreach (SiteIndustries::groups() as $groupSlug => $group) {
            $response = $this->get('/industries/'.$groupSlug)->assertOk()->assertSee($group['title'])->assertSee($group['shared_title']);

            foreach (SiteIndustries::pagesIn($groupSlug) as $slug => $page) {
                $response->assertSee('href="'.route('site.industry', $slug).'"', false)->assertSee($page['summary']);
            }
            foreach ($group['items'] as [, $title]) {
                $response->assertSee($title);
            }
            foreach ($group['related'] as $related) {
                $this->assertNotNull(SiteFeatures::find($related), "$groupSlug relates to unknown feature $related");
                $response->assertSee('href="'.route('site.feature', $related).'"', false);
            }
            foreach (array_diff(SiteIndustries::groupSlugs(), [$groupSlug]) as $other) {
                $response->assertSee('href="'.route('site.industry', $other).'"', false);
            }

            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $json);
            $breadcrumbs = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'BreadcrumbList');
            $this->assertSame(['Home', 'Industries', $group['name']], array_column($breadcrumbs['itemListElement'], 'name'), $groupSlug);
        }
    }

    public function test_groups_not_yet_proven_on_live_shops_say_new_on_every_page(): void
    {
        foreach (['online-sellers', 'service-businesses'] as $groupSlug) {
            $group = SiteIndustries::findGroup($groupSlug);
            $this->assertSame('New', $group['badge']);
            $this->get('/industries/'.$groupSlug)->assertSee('role="note"', false)->assertSee($group['notice']);

            foreach (SiteIndustries::pagesIn($groupSlug) as $slug => $page) {
                $this->assertSame('New', $page['badge'], $slug);
                $this->get('/industries/'.$slug)->assertSee('role="note"', false)->assertSee($page['notice']);
            }
        }

        // Pages whose own risk is specific say so in their own words.
        $this->get('/industries/daraz-seller')->assertSee('haven’t been run on a real seller’s file yet');
        $this->get('/industries/gym')->assertSee('It hasn’t run on a real device yet');
        $this->get('/industries/online-brand')->assertSee('haven’t connected a live store yet');

        foreach (['retail-shops', 'wholesale-distribution'] as $groupSlug) {
            $this->get('/industries/'.$groupSlug)->assertDontSee('role="note"', false);
        }
        $this->get('/industries/distributor')->assertDontSee('role="note"', false);
    }

    public function test_no_industry_page_claims_what_is_not_built(): void
    {
        foreach (SiteIndustries::slugs() as $slug) {
            $text = strtolower(html_entity_decode(strip_tags($this->get('/industries/'.$slug)->getContent())));
            foreach (['order booker app', 'order-booker app', 'booker app', 'van sales', 'gps', 'fbr integration', 'restaurant'] as $claim) {
                $this->assertStringNotContainsString($claim, $text, "/industries/$slug claims “{$claim}”");
            }
        }

        // The distributor page states the gap plainly instead.
        $this->get('/industries/distributor')->assertSee('There’s no order-booker mobile app or van stock count yet');
    }

    public function test_the_old_mobile_shop_feature_url_moves_permanently_under_shops(): void
    {
        $this->assertNull(SiteFeatures::find('mobile-shop-services'));
        $this->get('/features/mobile-shop-services')->assertStatus(301)->assertRedirect('/industries/mobile-shop');

        $this->get('/industries/mobile-shop')->assertOk()
            ->assertSee('Every income stream, not just product sales')
            ->assertSee('href="'.route('site.industry', 'retail-shops').'"', false);

        // /features#services still lands on the trade tools, which lead to every group.
        $features = $this->get('/features')->assertOk();
        foreach (SiteIndustries::groupSlugs() as $groupSlug) {
            $features->assertSee('href="'.route('site.industry', $groupSlug).'"', false);
        }
    }

    public function test_industries_menu_is_separate_from_features_and_lists_every_group_and_business_type(): void
    {
        $menu = SiteIndustries::menu();
        $this->assertSame(SiteIndustries::groupSlugs(), array_column($menu, 'slug'));
        $urls = [];
        foreach ($menu as $group) {
            $urls[] = $group['url'];
            $this->assertSame(array_keys(SiteIndustries::pagesIn($group['slug'])), array_column($group['items'], 'slug'));
            foreach ($group['items'] as $item) {
                $urls[] = $item['url'];
            }
        }
        $this->assertCount(19, $urls);

        foreach (['/', '/pricing', '/industries', '/industries/pharmacy', '/industries/online-sellers', '/features/udhaar'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('id="site-industries-menu"', $html, $path);
            $this->assertStringContainsString('id="site-mobile-industries"', $html, $path);
            $this->assertStringContainsString('aria-label="Show all industries"', $html, $path);

            // The desktop Features dropdown holds capability pages only.
            $features = str($html)->after('id="site-features-menu"')->before('id="site-industries-menu"')->toString();
            $this->assertStringNotContainsString(url('/industries').'/', $features, "$path: an industry page in the Features menu");

            $desktop = str($html)->after('id="site-industries-menu"')->before('id="site-mobile-menu"')->toString();
            $mobile = str($html)->after('id="site-mobile-industries"')->before('</header>')->toString();
            foreach ($urls as $url) {
                $this->assertStringContainsString('href="'.$url.'"', $desktop, "$path desktop → $url");
                $this->assertStringContainsString('href="'.$url.'"', $mobile, "$path mobile → $url");
            }
        }

        $activePill = '#<div class="flex items-center rounded-full transition-colors bg-brand-50 text-brand-700">\s*<a href="'.preg_quote(route('site.industries'), '#').'"#';
        foreach (['/industries/kiryana-store', '/industries/service-businesses'] as $path) {
            $this->assertMatchesRegularExpression($activePill, $this->get($path)->getContent(), $path);
        }
        $this->assertDoesNotMatchRegularExpression($activePill, $this->get('/features/pos')->getContent());

        $this->assertMatchesRegularExpression(
            '#href="'.preg_quote(route('site.industry', 'salon'), '#').'" class="[^"]*bg-brand-50[^"]*"\s+aria-current="page"#',
            $this->get('/industries/salon')->getContent(),
        );
    }

    public function test_the_industries_overview_shows_every_group_with_its_business_types_and_no_unbuilt_group(): void
    {
        $response = $this->get('/industries')->assertOk();

        foreach (SiteIndustries::menu() as $group) {
            $response->assertSee('id="'.$group['slug'].'"', false)->assertSee('href="'.$group['url'].'"', false)->assertSee($group['summary']);
            foreach ($group['items'] as $industry) {
                $response->assertSee('href="'.$industry['url'].'"', false)->assertSee($industry['summary']);
            }
        }

        $text = strtolower(strip_tags($response->getContent()));
        foreach (['bakery', 'bakeries', 'restaurant', 'school'] as $unbuilt) {
            $this->assertStringNotContainsString($unbuilt, $text);
        }
    }

    public function test_home_footer_and_who_its_for_follow_the_industry_list(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $whoItsFor = str($html)->after('Made for the way you do business')->before('See every industry')->toString();
        foreach (SiteIndustries::menu() as $group) {
            $this->assertStringContainsString('href="'.$group['url'].'"', $whoItsFor, $group['slug']);
            foreach ($group['items'] as $industry) {
                $this->assertStringContainsString('href="'.$industry['url'].'"', $whoItsFor, $industry['slug']);
                $this->assertStringContainsString('>'.e($industry['short']).'</a>', $whoItsFor, $industry['slug']);
            }
        }

        $footer = str($html)->after('<footer')->before('</footer>')->toString();
        foreach (SiteIndustries::menu() as $group) {
            $this->assertStringContainsString('<a href="'.$group['url'].'" class="hover:text-brand-700">'.e($group['name']).'</a>', $footer);
        }
        $this->assertStringContainsString('<a href="'.route('site.industries').'" class="hover:text-brand-700">All industries</a>', $footer);
        $this->assertStringContainsString('salons, gyms and workshops', $footer);

        $this->get('/')->assertSee('Does it work for salons, gyms and workshops?')->assertSee('Can a wholesaler or distributor use it?');
        $this->get('/about')->assertSee('workshops, salons, spas, gyms and trainers');
    }

    public function test_pricing_names_counter_services_plainly_and_lists_every_groups_tools(): void
    {
        foreach (\App\Models\Plan::active()->get() as $plan) {
            $plan->update(['feature_flags' => ['services' => true] + ($plan->feature_flags ?? [])]);
        }

        $this->get('/pricing')->assertOk()
            ->assertSee('Counter services (mobile shops)')
            ->assertDontSee('Services module')
            ->assertSee('Trade prices, credit limits &amp; dues aging for wholesalers', false)
            ->assertSee('Sales orders, load sheets, routes &amp; trade schemes for distributors', false)
            ->assertSee('Delivery charges, courier payouts &amp; RTO returns for online sellers', false)
            ->assertSee('Appointments, staff commission &amp; memberships for salons, spas &amp; gyms', false)
            ->assertSee('Job cards with estimates for repair workshops')
            ->assertSee('Is there a separate plan for wholesalers, online sellers or salons?')
            ->assertSee('appointments, job cards and memberships for service businesses');

        // The rename is site copy only: the plan data keeps its own label.
        $this->assertSame('Services module', \App\Enums\FeatureKey::Services->label());
    }

    public function test_stale_copy_is_gone_and_new_capabilities_are_described(): void
    {
        $this->assertSame(['CRM', 'ERP'], array_column(SiteFeatures::ROADMAP, 0));
        foreach (['/', '/pricing', '/features'] as $path) {
            $this->get($path)->assertDontSee('HR management');
        }

        $this->get('/features/purchase-orders')->assertSee('Wholesalers, traders, distributors and multi-location shops');
        $this->get('/features/online-store')->assertSee('Shopify & WooCommerce')->assertSee('connect a Shopify or WooCommerce store from Settings');
        $this->get('/features/courier-booking')->assertSee('COD payouts')->assertSee('Returned parcels (RTO)')->assertSee('Any courier, typed in');

        $this->assertSame('Business Management Software', config('branding.tagline'));
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
