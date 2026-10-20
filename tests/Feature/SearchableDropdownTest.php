<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Network;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SearchableDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_select_dropdown_renders_a_search_input_and_keeps_its_options_bound_to_the_hidden_native_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Walk-in Regular', 'phone' => '03001234567']);

        $this->actingAs($owner);

        $component = Livewire::test('sales.create');

        $component->assertSeeHtml('x-ref="search"');
        $component->assertSeeHtml('<option value="'.$customer->id.'">Walk-in Regular</option>');

        $component->set('customerId', (string) $customer->id)
            ->assertSet('customerId', (string) $customer->id);
    }

    public function test_selecting_the_create_option_through_the_livewire_property_still_works_once_the_dropdown_is_searchable(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    public function test_the_select_dropdowns_option_list_stays_tenant_scoped_once_searchable(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        Customer::create(['shop_id' => $shopA->id, 'name' => 'Shop A Customer']);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        $this->actingAs($ownerA);

        Livewire::test('sales.create')
            ->assertSee('Shop A Customer')
            ->assertDontSee('Shop B Customer');
    }

    public function test_an_image_select_dropdown_renders_a_search_input_alongside_its_existing_keyboard_navigation(): void
    {
        Storage::fake('public');

        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Network::create(['shop_id' => $shop->id, 'name' => 'Jazz', 'color' => '#ff0000']);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->assertSeeHtml('x-ref="search"')
            ->assertSeeHtml('keydown.arrow-down.prevent="move(1)"');
    }

    public function test_dropdown_components_do_not_introduce_any_new_wire_click_markup(): void
    {
        $select = file_get_contents(resource_path('views/components/ui/select.blade.php'));
        $imageSelect = file_get_contents(resource_path('views/components/ui/image-select.blade.php'));

        $this->assertStringNotContainsString('wire:click', $select);
        $this->assertStringNotContainsString('wire:click', $imageSelect);
    }
}
