<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::flushState();
    }

    public static function pages(): array
    {
        return [
            'home' => ['/', 'POS & Business Management Software in Pakistan | HisabOKitab'],
            'features' => ['/features', 'Features: POS, Inventory, Udhaar, Payroll & WhatsApp | HisabOKitab'],
            'pricing' => ['/pricing', 'Pricing & Plans in PKR | HisabOKitab'],
            'about' => ['/about', 'About Us: Business Software Made in Pakistan | HisabOKitab'],
            'contact' => ['/contact', 'Contact Us: WhatsApp, Email & Phone | HisabOKitab'],
            'feature: pos' => ['/features/pos', 'POS Billing & Invoicing Software | HisabOKitab'],
            'feature: stock' => ['/features/stock', 'Inventory & Stock Management Software | HisabOKitab'],
            'feature: purchase-orders' => ['/features/purchase-orders', 'Purchase Order & Goods Receipt Software | HisabOKitab'],
            'feature: udhaar' => ['/features/udhaar', 'Udhaar & Customer Ledger Software | HisabOKitab'],
            'feature: cash-balances' => ['/features/cash-balances', 'Cash in Hand, JazzCash, Easypaisa & Bank Balances | HisabOKitab'],
            'feature: reports' => ['/features/reports', 'Business Reports, Profit & Expense Tracking | HisabOKitab'],
            'feature: double-entry-ledger' => ['/features/double-entry-ledger', 'Double-Entry Accounting Ledger for Small Businesses | HisabOKitab'],
            'feature: online-store' => ['/features/online-store', 'Connect Your Online Store: Live Stock & Orders API | HisabOKitab'],
            'feature: payroll' => ['/features/payroll', 'Payroll & Salary Software for Small Businesses | HisabOKitab'],
            'feature: multi-branch' => ['/features/multi-branch', 'Multi-Branch Business Software | HisabOKitab'],
            'feature: whatsapp-ordering' => ['/features/whatsapp-ordering', 'WhatsApp Ordering Chatbot for Your Business | HisabOKitab'],
            'feature: social-posting' => ['/features/social-posting', 'Facebook & Instagram Posting from Your Product List | HisabOKitab'],
            'feature: meta-ads' => ['/features/meta-ads', 'Run Facebook, Instagram & WhatsApp Ads | HisabOKitab'],
            'feature: mobile-shop-services' => ['/features/mobile-shop-services', 'Mobile Shop Software: Repairs, Loads, SIMs & Bills | HisabOKitab'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function test_every_public_page_renders_with_its_own_title_description_and_one_h1(string $path, string $title): void
    {
        $response = $this->get($path)->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<title>'.e($title).'</title>', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{50,}">/', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url($path === '/' ? '' : $path).'">', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringNotContainsString('livewire', strtolower($html));
        $response->assertSee('Log in')->assertSee('Get started');
    }

    public function test_a_logged_in_user_still_goes_straight_to_their_shop_from_the_root(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_a_super_admin_still_goes_to_the_admin_dashboard_from_the_root(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        $this->get('/')->assertRedirect(route('admin.dashboard'));
    }

    public function test_logged_in_users_can_browse_the_site_and_are_offered_their_dashboard(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        $this->get('/pricing')->assertOk()->assertSee('Open dashboard')->assertDontSee('href="'.route('login').'"', false);
    }

    public function test_login_is_unchanged_and_still_reachable(): void
    {
        $this->get('/login')->assertOk()->assertSee(config('branding.name'));
    }

    public function test_contact_details_come_from_config(): void
    {
        config(['site.contact.email' => 'team@example.pk', 'site.contact.whatsapp' => '923451234567', 'site.contact.phone' => '+92 345 1234567']);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('mailto:team@example.pk', false)
            ->assertSee('https://wa.me/923451234567', false)
            ->assertSee('tel:+923451234567', false)
            ->assertSee('Karachi, Pakistan');
    }

    public function test_the_footer_links_to_each_page_and_lists_the_real_contact_details(): void
    {
        $footer = str($this->get('/')->assertOk()->getContent())->after('<footer')->before('</footer>')->toString();

        foreach (['site.features' => 'Features', 'site.pricing' => 'Pricing', 'site.about' => 'About', 'login' => 'Log in'] as $route => $label) {
            $this->assertStringContainsString('<a href="'.route($route).'" class="hover:text-brand-700">'.$label.'</a>', $footer);
        }

        $this->assertStringContainsString('<a href="https://wa.me/923272754688" class="hover:text-brand-700" rel="noopener">WhatsApp us</a>', $footer);
        $this->assertStringContainsString('<a href="https://wa.me/923272754688" class="hover:text-brand-700" rel="noopener">+92 327 2754688</a>', $footer);
        $this->assertStringContainsString('<a href="https://wa.me/923282827085" class="hover:text-brand-700" rel="noopener">+92 328 2827085</a>', $footer);
        $this->assertStringContainsString('<a href="mailto:info@hisabokitab.com" class="hover:text-brand-700">info@hisabokitab.com</a>', $footer);
        $this->assertStringNotContainsString('placeholder@', $footer);
    }

    public function test_only_future_ad_channels_are_marked_coming_soon(): void
    {
        foreach (['/', '/features/meta-ads'] as $path) {
            $html = $this->get($path)->getContent();

            foreach (['Google Ads', 'YouTube Ads', 'LinkedIn Ads'] as $channel) {
                $this->assertMatchesRegularExpression('/'.$channel.'\s*<span[^>]*>Coming soon/', $html);
            }

            $this->assertDoesNotMatchRegularExpression('/(WhatsApp|Meta|Facebook|Instagram|Status)[^<]{0,40}<span[^>]*>Coming soon/', $html);
        }
    }

    public function test_planned_modules_are_only_ever_shown_as_coming_soon(): void
    {
        foreach (['/', '/pricing', '/features'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('Planned, not available yet', $html, $path);
            foreach (array_column(\App\Support\SiteFeatures::ROADMAP, 0) as $module) {
                $this->assertMatchesRegularExpression('/'.preg_quote($module, '/').'\s*<span[^>]*>Coming soon/', $html, "$path: $module");
            }
        }

        $names = strtolower(implode(' ', array_column(\App\Support\SiteFeatures::all(), 'name')).' '.implode(' ', \App\Support\SiteFeatures::slugs()));
        foreach (['crm', 'erp', 'hr management'] as $module) {
            $this->assertStringNotContainsString($module, $names);
        }

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $this->get('/')->getContent(), $json);
        $software = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'SoftwareApplication');
        $this->assertNotEmpty(array_intersect(['Double-Entry Ledger', 'Online Store Connection'], $software['featureList']));
        $this->assertEmpty(preg_grep('/CRM|ERP|HR/', $software['featureList']));
    }

    public function test_the_ledger_and_online_store_pages_say_plainly_they_are_not_on_for_everyone(): void
    {
        $this->get('/features/double-entry-ledger')->assertOk()
            ->assertSee('role="note"', false)
            ->assertSee('The ledger is off by default and isn’t switched on for any business automatically.')
            ->assertSee('On request');

        $this->get('/features/online-store')->assertOk()
            ->assertSee('role="note"', false)
            ->assertSee('no store is using it live yet')
            ->assertSee('needs your store’s developer');

        $features = $this->get('/features')->getContent();
        $this->assertMatchesRegularExpression('/Double-Entry Ledger<span[^>]*>On request/', $features);
        $this->assertMatchesRegularExpression('/Online Store Connection<span[^>]*>New/', $features);
    }

    public function test_no_page_claims_organic_whatsapp_status_posting(): void
    {
        foreach (array_column(self::pages(), 0) as $path) {
            $text = strtolower(strip_tags($this->get($path)->getContent()));

            $this->assertDoesNotMatchRegularExpression('/(post|posting|publish|update)s?\s+(to|on|in)\s+(your\s+)?whatsapp status/', $text, $path);
        }
    }

    public function test_every_page_has_a_unique_title_and_description_of_a_sensible_length(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (array_column(self::pages(), 0) as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('#<title>(.*?)</title>#', $html, $title);
            preg_match('#<meta name="description" content="([^"]*)">#', $html, $description);
            $title = html_entity_decode($title[1]);
            $description = html_entity_decode($description[1]);

            $this->assertLessThanOrEqual(70, mb_strlen($title), "$path title: $title");
            $this->assertGreaterThanOrEqual(110, mb_strlen($description), "$path description is thin");
            $this->assertLessThanOrEqual(200, mb_strlen($description), "$path description is too long");
            $titles[] = $title;
            $descriptions[] = $description;
        }

        $this->assertSame($titles, array_values(array_unique($titles)), 'Two pages share a title.');
        $this->assertSame($descriptions, array_values(array_unique($descriptions)), 'Two pages share a description.');
    }

    public function test_every_page_carries_structured_data_and_social_tags(): void
    {
        foreach (array_column(self::pages(), 0) as $path) {
            $html = $this->get($path)->getContent();
            $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $json), $path);
            $data = json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
            $types = array_column($data['@graph'], '@type');
            $this->assertContains('Organization', $types, $path);
            $this->assertContains('WebSite', $types, $path);
            if ($path !== '/') {
                $this->assertContains('BreadcrumbList', $types, $path);
            }

            $this->assertStringContainsString('<meta property="og:image" content="'.\Illuminate\Support\Facades\Vite::asset('resources/images/site/og-image.png').'">', $html);
            $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
            $this->assertStringNotContainsString('name="robots"', $html, $path);
        }

        $this->assertSame([1200, 630], array_slice(getimagesize(resource_path('images/site/og-image.png')), 0, 2));
    }

    public function test_home_and_pricing_faq_markup_matches_the_visible_questions_and_images_have_alt_text(): void
    {
        foreach (['/', '/pricing'] as $path) {
            $html = $this->get($path)->getContent();
            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $json);
            $faq = collect(json_decode($json[1], true)['@graph'])->firstWhere('@type', 'FAQPage');
            $this->assertNotNull($faq, $path);
            foreach ($faq['mainEntity'] as $question) {
                $this->assertStringContainsString(e($question['name']), $html, $path);
            }
        }

        foreach (array_column(self::pages(), 0) as $path) {
            preg_match_all('#<img\b[^>]*>#', $this->get($path)->getContent(), $images);
            foreach ($images[0] as $image) {
                // Empty alt is allowed: the only one is decoration inside a mockup that has its own aria-label.
                $this->assertMatchesRegularExpression('#\salt="#', $image, $path);
            }
        }
    }

    public function test_signup_completion_login_and_app_paths_stay_out_of_search(): void
    {
        $this->get('/login')->assertSee('<meta name="robots" content="noindex, follow">', false);
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Sitemap: '.route('site.sitemap'), $robots);
    }

    public function test_every_image_the_site_uses_ships_inside_the_build(): void
    {
        // The live web root only gets public/build on deploy, so a site image anywhere else in public/ goes missing.
        foreach (['/', '/features', '/features/pos', '/pricing'] as $path) {
            preg_match_all('#(?:src|srcset|content)="([^"]+\.(?:png|webp|jpe?g|svg))#', $this->get($path)->getContent(), $urls);
            foreach (array_unique($urls[1]) as $url) {
                $this->assertStringContainsString('/build/assets/', $url, "$path uses $url");
            }
        }
        $this->assertDirectoryDoesNotExist(public_path('branding/site'));
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_sitemap_lists_every_public_page(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');

        foreach (['home', 'site.features', 'site.pricing', 'site.about', 'site.contact'] as $name) {
            $response->assertSee('<loc>'.route($name).'</loc>', false);
        }

        foreach (\App\Support\SiteFeatures::slugs() as $slug) {
            $response->assertSee('<loc>'.route('site.feature', $slug).'</loc>', false);
        }
        $this->assertMatchesRegularExpression('#<lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $response->getContent());
        $response->assertDontSee('signup/complete');
    }
}
