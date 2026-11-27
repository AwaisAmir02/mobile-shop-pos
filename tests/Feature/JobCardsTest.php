<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\JobCards;
use App\Enums\JobCardStatus;
use App\Models\JobCard;
use App\Models\Sale;
use App\Support\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Concerns\ServiceTradeFixtures;
use Tests\TestCase;

/** Repair workshop job cards: units, parts and labour, estimate, ready, billing, warranty. */
class JobCardsTest extends TestCase
{
    use RefreshDatabase, ServiceTradeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openServiceShop('repair_workshop');
    }

    protected function job(array $fields = []): JobCard
    {
        return JobCards::open(['customer_id' => $this->client->id, 'technician_id' => $this->ali->id, 'warranty_days' => 30] + $fields, [
            ['kind' => 'bike', 'label' => 'Honda CD70', 'details' => ['reg_no' => 'LEB-1234', 'odometer' => '45000', 'nonsense' => 'x'], 'problem' => 'Engine noise'],
            ['kind' => 'ac', 'label' => 'Haier 1.5 ton', 'details' => ['tonnage' => '1.5', 'gas' => 'R410']],
        ], $this->shop->id, $this->owner->id);
    }

    protected function priced(JobCard $job): JobCard
    {
        return JobCards::saveLines($job, [
            ['kind' => 'part', 'product_id' => $this->shampoo->id, 'quantity' => 2, 'unit_price' => 1000],
            ['kind' => 'labour', 'description' => 'Engine tuning', 'quantity' => 1, 'unit_price' => 1500, 'employee_id' => $this->sara->id],
        ]);
    }

    public function test_a_job_holds_several_units_with_their_own_details(): void
    {
        $job = $this->job();

        $this->assertSame(2, $job->items->count());
        $this->assertSame(['reg_no' => 'LEB-1234', 'odometer' => '45000'], $job->items[0]->details);
        $this->assertSame('Honda CD70 (LEB-1234), Haier 1.5 ton', $job->unitsLabel());
        $this->assertSame(JobCardStatus::Received, $job->status);
    }

    public function test_priced_parts_are_held_and_a_changed_estimate_needs_approval_again(): void
    {
        $job = $this->priced($this->job());
        $this->assertSame(3500.0, $job->total());
        $this->assertSame(8, StockAvailability::freeFor($this->shampoo->fresh()));

        $job = JobCards::approveEstimate($job, 'By phone');
        $this->assertNotNull($job->estimate_approved_at);

        $job = JobCards::saveLines($job, [['kind' => 'labour', 'description' => 'Extra', 'quantity' => 1, 'unit_price' => 500]]);
        $this->assertNull($job->estimate_approved_at);
    }

    public function test_a_site_visit_needs_an_address_and_bills_its_charge(): void
    {
        try {
            $this->job(['site_visit' => true]);
            $this->fail('No address.');
        } catch (ValidationException) {
        }

        $job = $this->job(['site_visit' => true, 'site_address' => 'House 5, DHA', 'visit_charge' => 500]);
        $this->assertSame(500.0, $job->fresh()->total());
        $this->assertSame('Site visit', JobCards::billLines($job)['services'][0]['name']);
    }

    public function test_ready_without_whatsapp_gives_a_link_to_send(): void
    {
        $job = $this->priced($this->job());
        $result = JobCards::move($job, JobCardStatus::Ready, $this->owner->id);

        $this->assertNotSame('sent', $result['notified']);
        $this->assertStringStartsWith('https://wa.me/923001234567?text=', JobCards::whatsappLink($this->client->phone, JobCards::readyText($job->fresh(), 'Glow')));

        Volt::test('job-cards.show', ['jobCard' => $job->fresh()])->call('setStatus', 'ready')->assertSee('data-ready-link', false);
    }

    public function test_billing_takes_parts_from_stock_delivers_and_starts_the_warranty(): void
    {
        $job = $this->priced($this->job());

        \Livewire\Livewire::withQueryParams(['job_card' => $job->id])->test('sales.create')
            ->assertSet('customerId', (string) $this->client->id)
            ->call('completeSale')->assertHasNoErrors();

        $job->refresh();
        $this->assertSame(JobCardStatus::Delivered, $job->status);
        $this->assertSame('2026-12-10', $job->warranty_until->toDateString());
        $this->assertSame(8, (int) $this->shampoo->fresh()->stock_quantity);
        $sale = Sale::findOrFail($job->sale_id);
        $this->assertSame('3500.00', (string) $sale->total);
        $this->assertSame($this->sara->id, $sale->items()->where('line_kind', 'service')->first()->performed_by);

        CancelSale::handle($sale, $this->owner->id, 'x');
        $this->assertSame(JobCardStatus::Ready, $job->fresh()->status);
        $this->assertNull($job->fresh()->warranty_until);
    }

    public function test_screens_open_and_search_finds_by_registration(): void
    {
        $job = $this->job();
        $this->get(route('job-cards.index', ['q' => 'LEB-1234']))->assertOk()->assertSee($job->number());
        $this->get(route('job-cards.show', $job))->assertOk()->assertSee('Honda CD70');
        $this->get(route('job-cards.create'))->assertOk();

        Volt::test('job-cards.create')
            ->set('customerId', (string) $this->client->id)
            ->set('items.0.label', 'Suzuki Mehran')
            ->set('items.0.details.reg_no', 'ABC-1')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(2, JobCard::count());
    }

    public function test_the_job_card_pdf_renders(): void
    {
        $job = $this->priced($this->job());
        $html = view('pdf.job-card', ['job' => $job->load(['shop', 'customer', 'technician', 'items', 'lines']), 'estimate' => true, 'deposits' => 0])->render();

        $this->assertStringContainsString('Estimate', $html);
        $this->assertStringContainsString('LEB-1234', $html);
        $this->assertStringContainsString('3,500.00', $html);
    }
}
