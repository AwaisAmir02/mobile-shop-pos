<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Network;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImageSelectDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_the_network_dropdown_carries_each_networks_image_url_and_color(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $network = Network::create([
            'shop_id' => $shop->id,
            'name' => 'Jazz',
            'color' => '#ff0000',
            'image_path' => 'shop-'.$shop->id.'/networks/jazz.jpg',
        ]);
        Storage::disk('public')->put($network->image_path, 'fake-image-content');

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->assertViewHas('networkOptions', function ($options) use ($network) {
                $jazz = collect($options)->firstWhere('value', 'Jazz');

                return $jazz
                    && $jazz['color'] === '#ff0000'
                    && $jazz['image'] === $network->imageUrl();
            })
            ->assertDontSee('<option value="Jazz">', false);
    }

    public function test_the_accessory_category_dropdown_carries_each_categorys_image_and_a_leading_create_option(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        $category = AccessoryCategoryOption::create([
            'shop_id' => $shop->id,
            'main_category_id' => $accessory->id,
            'name' => 'Case / Cover',
            'image_path' => 'shop-'.$shop->id.'/accessory-categories/case.jpg',
        ]);
        Storage::disk('public')->put($category->image_path, 'fake-image-content');

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertViewHas('accessoryCategoryOptions', function ($options) use ($category) {
                $options = collect($options);

                return $options->first()['value'] === '__create__'
                    && $options->first()['special'] === true
                    && $options->firstWhere('value', 'Case / Cover')['image'] === $category->imageUrl();
            })
            ->assertDontSee('<option value="Case / Cover">', false);
    }

    public function test_the_wallet_provider_dropdown_carries_each_providers_image_url(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $provider = WalletProvider::create([
            'shop_id' => $shop->id,
            'name' => 'SadaPay',
            'image_path' => 'shop-'.$shop->id.'/wallet-providers/sadapay.jpg',
        ]);
        Storage::disk('public')->put($provider->image_path, 'fake-image-content');

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->assertViewHas('providerOptions', function ($options) use ($provider) {
                $sadaPay = collect($options)->firstWhere('value', 'SadaPay');

                return $sadaPay && $sadaPay['image'] === $provider->imageUrl();
            })
            ->assertDontSee('<option value="SadaPay">', false);
    }

    public function test_selecting_the_new_category_option_via_the_livewire_property_still_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('category', '__create__')
            ->assertDispatched('open-modal', name: 'quick-create-accessory-category')
            ->assertSet('category', '');
    }
}
