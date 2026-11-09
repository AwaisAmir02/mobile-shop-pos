<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Design overhaul D10: layout pins for the last screens and two shared fixes.
 */
class FinalScreensLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_table_cell_that_asks_to_wrap_drops_nowrap(): void
    {
        // Both classes on one cell let nowrap win, since it comes later in the CSS.
        $wrapping = Blade::render('<x-ui.table-cell class="whitespace-normal">Long text</x-ui.table-cell>');
        $plain = Blade::render('<x-ui.table-cell>Short</x-ui.table-cell>');

        $this->assertStringContainsString('whitespace-normal', $wrapping);
        $this->assertStringNotContainsString('whitespace-nowrap', $wrapping);
        $this->assertStringContainsString('whitespace-nowrap', $plain);
    }

    public function test_login_greets_and_links_to_signup(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('Create your shop')
            ->assertSee(route('site.signup'), false);
    }

    public function test_the_site_mockup_no_longer_promises_automatic_printing(): void
    {
        $this->get('/')->assertOk()->assertDontSee('print invoice')->assertSee('Complete sale');
    }

    public function test_the_report_builder_labels_cells_for_phone_cards(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));
        \App\Models\Product::create(['shop_id' => $shop->id, 'name' => 'Cable', 'type' => 'accessory', 'price' => 100]);

        Livewire::test('reports.index')
            ->assertSeeHtml('ui-stack')
            ->assertSeeHtml('ui-cell-primary')
            ->assertSeeHtml('data-label="');
    }
}
