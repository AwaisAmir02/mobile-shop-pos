<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Wallet Provider / Shop Account "+ New" bug happened because the
 * dropdown's own click handler set the Livewire property directly and
 * relied on a server round-trip (an updated{Property}() hook) to notice
 * the special "__create__" value and open the modal — a real click never
 * reliably triggered that hook, so the sentinel's own label stuck as the
 * visible "selection" and the modal never opened. Livewire's PHP test
 * harness cannot execute the Alpine click handler itself (there is no JS
 * engine here), so these tests cannot prove the fix works when clicked —
 * only a real browser click-through can. What they DO prove is that the
 * data the click handler depends on is wired correctly: each special
 * option carries the exact modal name the click handler now dispatches
 * client-side (bypassing the server round-trip entirely), and that a
 * modal with that exact name actually exists in the rendered page for
 * the dispatched event to land on.
 */
class ImageSelectCreateOptionClickPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wallet_provider_dropdowns_create_option_names_a_modal_that_exists_in_the_page(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $component = Livewire::test('wallet-loads.create')
            ->assertViewHas('providerOptions', function ($options) {
                $create = collect($options)->firstWhere('value', '__create__');

                return $create
                    && $create['special'] === true
                    && $create['modal'] === 'quick-create-wallet-provider';
            });

        $component->assertSee('New Wallet Provider');
    }

    public function test_the_shop_account_dropdowns_create_option_names_a_modal_that_exists_in_the_page(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $component = Livewire::test('wallet-loads.create')
            ->assertViewHas('shopAccountOptions', function ($options) {
                $create = collect($options)->firstWhere('value', '__create__');

                return $create
                    && $create['special'] === true
                    && $create['modal'] === 'quick-create-shop-account';
            });

        $component->assertSee('New Shop Account');
    }

    public function test_the_accessory_category_dropdowns_create_option_names_a_modal_that_exists_in_the_page(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertViewHas('accessoryCategoryOptions', function ($options) {
                $create = collect($options)->firstWhere('value', '__create__');

                return $create
                    && $create['special'] === true
                    && $create['modal'] === 'quick-create-accessory-category';
            });

        // The quick-create component that owns the matching modal name is
        // nested inside products.index but isn't rendered in that test's
        // own HTML output, so its target is verified on its own render.
        Livewire::test('accessory-categories.quick-create')
            ->assertSee('New Sub-Category');
    }

    public function test_the_image_select_click_handler_never_writes_the_sentinel_value_to_the_livewire_property(): void
    {
        // The bug's visible symptom was the sentinel's own label ("New
        // Provider") sticking as the selected value. The fixed click
        // handler returns before touching $wire for a special option, so
        // this asserts the source of that fix is actually in place —
        // a regression here would silently reintroduce the bug even
        // though every property-level test above would still pass.
        $source = file_get_contents(resource_path('views/components/ui/image-select.blade.php'));

        $selectMethod = substr($source, strpos($source, 'select(option) {'));
        $selectMethod = substr($selectMethod, 0, strpos($selectMethod, "\n        },"));

        $specialCheckPos = strpos($selectMethod, 'option.special');
        $wireWritePos = strpos($selectMethod, '$wire.');

        $this->assertNotFalse($specialCheckPos, 'The special-option check is missing from select().');
        $this->assertNotFalse($wireWritePos, 'The $wire write is missing from select().');
        $this->assertTrue(
            $specialCheckPos < $wireWritePos,
            'The special-option check must run, and return, before $wire is ever written to — '.
            'otherwise the sentinel value can reach the Livewire property again.'
        );
    }
}
