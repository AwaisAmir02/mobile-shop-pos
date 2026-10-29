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
            'home' => ['/', 'HisabOKitab — Shop management software for every shop and online seller in Pakistan'],
            'features' => ['/features', 'Features | HisabOKitab'],
            'pricing' => ['/pricing', 'Pricing | HisabOKitab'],
            'about' => ['/about', 'About | HisabOKitab'],
            'contact' => ['/contact', 'Contact | HisabOKitab'],
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

    public function test_only_future_ad_channels_are_marked_coming_soon(): void
    {
        foreach (['/', '/features'] as $path) {
            $html = $this->get($path)->getContent();

            foreach (['Google Ads', 'YouTube Ads', 'LinkedIn Ads'] as $channel) {
                $this->assertMatchesRegularExpression('/'.$channel.'\s*<span[^>]*>Coming soon/', $html);
            }

            $this->assertDoesNotMatchRegularExpression('/(WhatsApp|Meta|Facebook|Instagram|Status)[^<]{0,40}<span[^>]*>Coming soon/', $html);
        }
    }

    public function test_no_page_claims_organic_whatsapp_status_posting(): void
    {
        foreach (array_column(self::pages(), 0) as $path) {
            $text = strtolower(strip_tags($this->get($path)->getContent()));

            $this->assertDoesNotMatchRegularExpression('/(post|posting|publish|update)s?\s+(to|on|in)\s+(your\s+)?whatsapp status/', $text, $path);
        }
    }

    public function test_sitemap_lists_every_public_page(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');

        foreach (['home', 'site.features', 'site.pricing', 'site.about', 'site.contact'] as $name) {
            $response->assertSee('<loc>'.route($name).'</loc>', false);
        }
    }
}
