<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\DeleteShop;
use App\Actions\UpdateSale;
use App\Enums\SaleSource;
use App\Models\BackupRun;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePrescription;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * P3: the prescription / controlled-drug register. The shop marks medicines
 * itself; every sale of one writes a numbered register entry with the
 * prescriber, or an emergency reason, and an optional private photo.
 */
class PrescriptionRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->shop = Shop::create(['name' => 'Shifa Medical', 'business_category' => 'pharmacy']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->actingAs($this->owner);
        MainCategory::ensureDefaultsExist($this->shop->id);
        MainCategory::create(['shop_id' => $this->shop->id, 'name' => 'Medicines', 'slug' => 'medicines']);
    }

    protected function medicine(string $name, ?string $control, float $price = 50): Product
    {
        $product = Product::create([
            'shop_id' => $this->shop->id, 'type' => 'medicines', 'name' => $name, 'price' => $price, 'cost_price' => $price / 2, 'stock_quantity' => 0,
            'rx_control' => $control, 'details' => ['category' => null],
        ]);
        CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 50, 'cost_price' => $price / 2, 'selling_price' => $price]], null, 'paid', null, '2026-10-01', null, $this->owner->id);

        return $product->fresh();
    }

    public function test_the_product_form_marks_a_medicine(): void
    {
        $product = Product::create(['shop_id' => $this->shop->id, 'type' => 'medicines', 'name' => 'Xanax 0.5mg', 'price' => 10, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->assertSee('Prescription only')
            ->set('rx_control', 'controlled')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('controlled', $product->fresh()->rx_control);
    }

    public function test_a_sale_with_a_prescriber_writes_numbered_register_entries(): void
    {
        $augmentin = $this->medicine('Augmentin 625mg', 'prescription');
        $panadol = $this->medicine('Panadol', null);

        Livewire::test('sales.create')
            ->call('addToCart', $augmentin->id)->set("cart.{$augmentin->id}.quantity", 2)
            ->call('addToCart', $panadol->id)
            ->assertSee('Prescription only: goes in the prescription register')
            ->assertSeeHtml('data-prescription-panel')
            ->set('prescription.patient_name', 'Bilal')
            ->set('prescription.prescriber_name', 'Dr. Sana Iqbal')
            ->set('prescription.prescriber_reg_no', 'PMDC-12345')
            ->set('prescription.prescribed_on', now()->toDateString())
            ->assertDontSeeHtml('data-emergency-sale')
            ->call('completeSale')->assertHasNoErrors();

        $entry = SalePrescription::sole();
        $this->assertSame([1, 'Augmentin 625mg', 2, 'Bilal', 'Dr. Sana Iqbal', 'PMDC-12345', false, 'register'], [
            $entry->serial_number, $entry->product_name, $entry->quantity, $entry->patient_name, $entry->prescriber_name, $entry->prescriber_reg_no, $entry->is_emergency, $entry->source,
        ]);
        $this->assertSame(Sale::sole()->id, $entry->sale_id);

        Livewire::test('sales.create')
            ->call('addToCart', $augmentin->id)
            ->set('prescription.prescriber_name', 'Dr. Ali')
            ->call('completeSale')->assertHasNoErrors();

        $this->assertSame([1, 2], SalePrescription::orderBy('id')->pluck('serial_number')->all());
    }

    public function test_no_prescriber_is_an_emergency_sale_that_needs_a_reason(): void
    {
        $inhaler = $this->medicine('Ventolin Inhaler', 'prescription');

        $register = Livewire::test('sales.create')
            ->call('addToCart', $inhaler->id)
            ->assertSeeHtml('data-emergency-sale')
            ->call('completeSale')
            ->assertHasErrors('prescription.emergency_reason');
        $this->assertSame(0, Sale::count(), 'nothing is saved');

        $register->set('prescription.emergency_reason', 'Asthma attack at the counter')->call('completeSale')->assertHasNoErrors();

        $entry = SalePrescription::sole();
        $this->assertTrue($entry->is_emergency);
        $this->assertSame('Asthma attack at the counter', $entry->emergency_reason);
        $this->assertNull($entry->prescriber_name);
    }

    public function test_a_controlled_drug_needs_the_patient(): void
    {
        $xanax = $this->medicine('Xanax 0.5mg', 'controlled');

        Livewire::test('sales.create')
            ->call('addToCart', $xanax->id)
            ->set('prescription.prescriber_name', 'Dr. Sana')
            ->call('completeSale')
            ->assertHasErrors('prescription.patient_name')
            ->set('prescription.patient_name', 'Ayesha')
            ->call('completeSale')
            ->assertHasNoErrors();

        $this->assertSame(['controlled', 'Ayesha'], [SalePrescription::sole()->control, SalePrescription::sole()->patient_name]);
    }

    public function test_the_photo_is_private_and_served_only_to_the_shops_users_who_can_open_sales(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');

        Livewire::test('sales.create')
            ->call('addToCart', $augmentin->id)
            ->set('prescription.prescriber_name', 'Dr. Sana')
            ->set('prescriptionPhoto', UploadedFile::fake()->image('rx.jpg'))
            ->call('completeSale')->assertHasNoErrors();

        $entry = SalePrescription::sole();
        $this->assertStringStartsWith('prescriptions/'.$this->shop->id.'/', $entry->photo_path);
        Storage::disk('local')->assertExists($entry->photo_path);

        $this->get(route('prescriptions.photo', $entry))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Livewire::test('sales.show', ['sale' => Sale::sole()])->assertSee('View prescription photo');

        $other = Shop::create(['name' => 'Other', 'business_category' => 'pharmacy']);
        $this->actingAs(User::factory()->create(['shop_id' => $other->id, 'is_owner' => true]));
        $this->get(route('prescriptions.photo', $entry))->assertNotFound();

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Stock keeper', 'permissions' => ['products']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $this->get(route('prescriptions.photo', $entry))->assertForbidden();

        auth()->logout();
        $this->get(route('prescriptions.photo', $entry))->assertRedirect();
    }

    public function test_a_sale_made_elsewhere_gets_entries_to_fill_in_from_the_sale_page(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');

        // A WhatsApp or online order: CreateSale without register details.
        $sale = CreateSale::handle([['product_id' => $augmentin->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->owner->id, now()->toDateString(), 50, attributes: ['source' => SaleSource::WhatsApp->value]);

        $entry = SalePrescription::sole();
        $this->assertTrue($entry->detailsMissing());
        $this->assertSame('whatsapp', $entry->source);

        Livewire::test('prescriptions.index')->set('show', 'missing')->assertSee('Not recorded yet');

        Livewire::test('sales.show', ['sale' => $sale])
            ->assertSee('Add details')
            ->call('openPrescription')
            ->call('savePrescription')
            ->assertHasErrors('prescription.emergency_reason')
            ->set('prescription.prescriber_name', 'Dr. Hamid')
            ->set('prescriptionPhoto', UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf'))
            ->call('savePrescription')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame('Dr. Hamid', $entry->prescriber_name);
        $this->assertFalse($entry->detailsMissing());
        Storage::disk('local')->assertExists($entry->photo_path);
    }

    public function test_a_new_photo_replaces_the_old_file(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');
        Livewire::test('sales.create')->call('addToCart', $augmentin->id)->set('prescription.prescriber_name', 'Dr. Sana')
            ->set('prescriptionPhoto', UploadedFile::fake()->image('one.jpg'))->call('completeSale')->assertHasNoErrors();
        $old = SalePrescription::sole()->photo_path;

        Livewire::test('sales.show', ['sale' => Sale::sole()])->call('openPrescription')
            ->set('prescriptionPhoto', UploadedFile::fake()->image('two.jpg'))->call('savePrescription')->assertHasNoErrors();

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists(SalePrescription::sole()->photo_path);
    }

    public function test_an_edit_follows_the_quantities_keeps_the_details_and_never_reuses_a_number(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');
        $brufen = $this->medicine('Brufen', 'prescription');
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Hina']);
        $details = ['patient_name' => 'Hina', 'prescriber_name' => 'Dr. Sana', 'prescriber_reg_no' => '', 'prescribed_on' => '', 'emergency_reason' => ''];

        $sale = CreateSale::handle([['product_id' => $augmentin->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => null]], 0.0, $customer->id, $this->owner->id, now()->toDateString(), 0, prescription: $details);

        $line = fn ($product, $quantity) => ['product_id' => $product->id, 'product_name' => $product->name, 'product_type' => 'medicines', 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null];
        UpdateSale::handle($sale->fresh(), [$line($augmentin, 3), $line($brufen, 1)], 0.0, $customer->id, $this->owner->id, now()->toDateString());

        $entries = SalePrescription::orderBy('serial_number')->get();
        $this->assertSame([[1, 3, 'Dr. Sana'], [2, 1, 'Dr. Sana']], $entries->map(fn ($e) => [$e->serial_number, $e->quantity, $e->prescriber_name])->all());

        UpdateSale::handle($sale->fresh(), [$line($augmentin, 3)], 0.0, $customer->id, $this->owner->id, now()->toDateString());
        $this->assertSame(0, SalePrescription::where('serial_number', 2)->sole()->quantity, 'kept at nothing given');

        $another = CreateSale::handle([['product_id' => $brufen->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->owner->id, now()->toDateString(), 50, prescription: $details);
        $this->assertSame(3, SalePrescription::where('sale_id', $another->id)->sole()->serial_number);
    }

    public function test_the_register_screen_lists_filters_and_marks_cancelled_sales(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');
        $xanax = $this->medicine('Xanax', 'controlled');
        CreateSale::handle([['product_id' => $augmentin->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->owner->id, now()->toDateString(), 50,
            prescription: ['patient_name' => '', 'prescriber_name' => '', 'prescriber_reg_no' => '', 'prescribed_on' => '', 'emergency_reason' => 'Night emergency']);
        $cancelled = CreateSale::handle([['product_id' => $xanax->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->owner->id, now()->toDateString(), 50,
            prescription: ['patient_name' => 'Zara', 'prescriber_name' => 'Dr. Kamal', 'prescriber_reg_no' => '', 'prescribed_on' => '', 'emergency_reason' => '']);
        CancelSale::handle($cancelled, $this->owner->id);

        Livewire::test('reports.hub')->assertSee('Prescription register');

        Livewire::test('prescriptions.index')
            ->assertSee('Emergency: Night emergency')
            ->assertSee('Dr. Kamal')
            ->assertSee('(cancelled)')
            ->assertViewHas('count', 1)
            ->set('show', 'controlled')
            ->assertSee('Xanax')
            ->assertDontSee('Night emergency');

        $other = Shop::create(['name' => 'Other', 'business_category' => 'pharmacy']);
        $this->actingAs(User::factory()->create(['shop_id' => $other->id, 'is_owner' => true]));
        Livewire::test('prescriptions.index')->assertDontSee('Dr. Kamal');
    }

    public function test_the_import_takes_the_prescription_column(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Main Category', 'Selling Price', 'Prescription'],
            ['Augmentin', 'Medicines', '300', 'Prescription only'],
            ['Xanax', 'Medicines', '20', 'Controlled'],
            ['Panadol', 'Medicines', '3', ''],
        ], null, 'A1');
        $path = Storage::disk('local')->path('test-imports/rx.xlsx');
        @mkdir(dirname($path), 0777, true);
        (new Xlsx($spreadsheet))->save($path);

        $service = app(ProductImportService::class);
        $preview = $service->preview($path, $this->shop->id);
        $this->assertTrue($preview['valid'], implode(' ', $preview['rowErrors']));
        $service->commit($path, $this->shop->id);

        $this->assertSame(['prescription', 'controlled', null], Product::whereIn('name', ['Augmentin', 'Xanax', 'Panadol'])->orderByRaw("FIELD(name, 'Augmentin', 'Xanax', 'Panadol')")->pluck('rx_control')->all());
    }

    public function test_the_photos_go_with_the_shop_when_it_is_deleted(): void
    {
        $augmentin = $this->medicine('Augmentin', 'prescription');
        Livewire::test('sales.create')->call('addToCart', $augmentin->id)->set('prescription.prescriber_name', 'Dr. Sana')
            ->set('prescriptionPhoto', UploadedFile::fake()->image('rx.jpg'))->call('completeSale')->assertHasNoErrors();
        $photo = SalePrescription::sole()->photo_path;

        BackupRun::create(['status' => BackupRun::STATUS_SUCCESS, 'trigger' => 'schedule', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        DeleteShop::handle($this->shop, 'Shifa Medical', User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        Storage::disk('local')->assertMissing($photo);
        $this->assertSame(0, SalePrescription::withoutGlobalScopes()->count());
    }
}
