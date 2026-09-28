<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\TableExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end confirmation that a real screen degrades to a toast (rather
 * than a long dompdf/PhpSpreadsheet run or a fatal error) once its export
 * would exceed TableExportService::MAX_ROWS — Sales History stands in for
 * all five export-wired screens since they all route through the same
 * TableExportServiceRowLimitTest-covered service and the same GuardsExportSize trait.
 */
class ExportRowLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporting_more_rows_than_the_limit_toasts_instead_of_downloading(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $now = now();
        $rows = array_fill(0, TableExportService::MAX_ROWS + 1, [
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'subtotal' => 100,
            'discount_amount' => 0,
            'total' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('sales')->insert($chunk);
        }

        $this->actingAs($owner);

        Livewire::test('sales.history')
            ->call('exportPdf')
            ->assertDispatched('toast', type: 'error');
    }
}
