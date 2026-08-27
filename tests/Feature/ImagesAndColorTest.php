<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\Network;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImagesAndColorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_uploading_a_product_image_stores_it_tenant_scoped_and_sets_image_path(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('name', 'Charger')
            ->set('image', UploadedFile::fake()->image('charger.jpg'))
            ->set('price', '1500')
            ->set('stock_quantity', '10')
            ->set('category', 'Charger')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Charger')->firstOrFail();
        $this->assertNotNull($product->image_path);
        $this->assertStringStartsWith('shop-'.$shop->id.'/products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertNotNull($product->imageUrl());
    }

    public function test_replacing_a_products_image_deletes_the_old_file(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('name', 'Charger')
            ->set('image', UploadedFile::fake()->image('first.jpg'))
            ->set('price', '1500')
            ->set('stock_quantity', '10')
            ->set('category', 'Charger')
            ->call('save');

        $product = Product::where('name', 'Charger')->firstOrFail();
        $originalPath = $product->image_path;
        Storage::disk('public')->assertExists($originalPath);

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->set('image', UploadedFile::fake()->image('second.jpg'))
            ->call('save');

        $product->refresh();
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertNotSame($originalPath, $product->image_path);
    }

    public function test_a_product_without_an_image_has_no_image_url_and_no_broken_reference(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'No Image Product',
            'price' => 100,
            'stock_quantity' => 5,
            'details' => [],
        ]);

        $this->assertNull($product->image_path);
        $this->assertNull($product->imageUrl());
    }

    public function test_uploading_an_accessory_category_image_is_tenant_scoped(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('accessoryCategoryName', 'Tempered Glass')
            ->set('accessoryCategoryImage', UploadedFile::fake()->image('glass.jpg'))
            ->call('saveAccessoryCategory')
            ->assertHasNoErrors();

        $option = AccessoryCategoryOption::where('name', 'Tempered Glass')->firstOrFail();
        $this->assertStringStartsWith('shop-'.$shop->id.'/accessory-categories/', $option->image_path);
        Storage::disk('public')->assertExists($option->image_path);
    }

    public function test_uploading_a_wallet_provider_image_is_tenant_scoped(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('providerName', 'SadaPay')
            ->set('providerImage', UploadedFile::fake()->image('logo.jpg'))
            ->call('saveProvider')
            ->assertHasNoErrors();

        $provider = WalletProvider::where('name', 'SadaPay')->firstOrFail();
        $this->assertStringStartsWith('shop-'.$shop->id.'/wallet-providers/', $provider->image_path);
        Storage::disk('public')->assertExists($provider->image_path);
    }

    public function test_a_network_can_be_saved_with_a_color_and_an_image(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('networkName', 'Warid')
            ->set('networkColor', '#ff0000')
            ->set('networkImage', UploadedFile::fake()->image('warid.jpg'))
            ->call('saveNetwork')
            ->assertHasNoErrors();

        $network = Network::where('name', 'Warid')->firstOrFail();
        $this->assertSame('#ff0000', $network->color);
        $this->assertStringStartsWith('shop-'.$shop->id.'/networks/', $network->image_path);
        Storage::disk('public')->assertExists($network->image_path);
    }

    public function test_an_invalid_color_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('networkName', 'Warid')
            ->set('networkColor', 'not-a-color')
            ->call('saveNetwork')
            ->assertHasErrors(['networkColor']);
    }

    public function test_a_non_image_file_is_rejected_by_validation(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('networkName', 'Warid')
            ->set('networkImage', UploadedFile::fake()->create('document.pdf', 100))
            ->call('saveNetwork')
            ->assertHasErrors(['networkImage']);
    }

    public function test_a_shops_uploaded_images_never_leak_into_another_shops_listing(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        $this->actingAs($ownerA);
        Livewire::test('settings.index')
            ->set('networkName', 'Shop A Network')
            ->set('networkColor', '#111111')
            ->set('networkImage', UploadedFile::fake()->image('a.jpg'))
            ->call('saveNetwork');

        $networkA = Network::where('name', 'Shop A Network')->firstOrFail();

        $this->actingAs($ownerB);
        Livewire::test('settings.index')
            ->assertDontSee('Shop A Network');

        // Shop B genuinely cannot query into shop A's tenant-scoped record.
        $this->assertNull(Network::find($networkA->id));
        $this->assertStringStartsWith('shop-'.$shopA->id.'/', $networkA->image_path);
    }
}
