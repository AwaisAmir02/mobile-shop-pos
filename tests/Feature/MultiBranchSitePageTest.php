<?php

namespace Tests\Feature;

use App\Support\SiteFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiBranchSitePageTest extends TestCase
{
    use RefreshDatabase;

    /** Phase 1 shares one stock pool; nothing on the site may promise more until Phase 2 exists. */
    private const PHASE_TWO_CLAIMS = ['transfer', 'per-branch stock', 'stock per branch', 'branch stock', 'its own stock', 'their own stock', 'separate stock', 'stock at each branch', 'each branch’s stock'];

    public function test_the_multi_branch_page_describes_phase_one(): void
    {
        $feature = SiteFeatures::find('multi-branch');
        $this->assertSame('branches', $feature['anchor']);

        $response = $this->get('/features/multi-branch')->assertOk()
            ->assertSee('One business, every location, one account')
            ->assertSee('One catalog, one stock pool')
            ->assertSee('Each branch keeps its own records')
            ->assertSee('Staff tied to their branch')
            ->assertSee('Switch branch in one click')
            ->assertSee('Branches side by side')
            ->assertSee('One balance per customer')
            ->assertSee('Grows with your plan');

        foreach ($feature['related'] as $related) {
            $response->assertSee('href="'.route('site.feature', $related).'"', false);
        }
    }

    public function test_no_site_page_claims_per_branch_stock_or_transfers(): void
    {
        foreach (['/', '/features', '/pricing', '/about', '/contact', ...array_map(fn ($slug) => '/features/'.$slug, SiteFeatures::slugs())] as $path) {
            $text = strtolower(html_entity_decode(strip_tags($this->get($path)->getContent())));

            foreach (self::PHASE_TWO_CLAIMS as $claim) {
                $this->assertStringNotContainsString($claim, $text, "{$path} mentions “{$claim}”");
            }
        }
    }

    public function test_multi_branch_is_woven_through_the_site(): void
    {
        $page = route('site.feature', 'multi-branch');

        $home = $this->get('/')->assertOk()->assertSee('One account for every location you run')->assertSee('Online sellers with a warehouse')->assertSee('Companies with several offices');
        $home->assertSee('href="'.$page.'"', false);
        $this->assertSame(2, substr_count($home->getContent(), '>Multi-branch</span>'), 'Once in the marquee, once in its hidden loop copy.');

        $this->get('/features')->assertOk()->assertSee('id="branches" href="'.$page.'"', false);
        $this->get('/features/reports')->assertSee('href="'.$page.'"', false);
        $this->get('/pricing')->assertOk()->assertSee('Can I run more than one branch?');
        $this->get('/sitemap.xml')->assertSee('<loc>'.$page.'</loc>', false);
    }
}
