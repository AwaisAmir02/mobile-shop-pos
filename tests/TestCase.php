<?php

namespace Tests;

use App\Actions\StartLedger;
use App\Ledger\LedgerPoster;
use App\Models\LedgerOutbox;
use App\Models\Shop;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * LEDGER_FORCE_ON=1 runs any test with the ledger started on every shop, then
     * posts everything and checks it balanced. Proves the ledger doesn't change
     * what a test sees, and pushes every test's data through the builders.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (env('LEDGER_FORCE_ON')) {
            Shop::created(function (Shop $shop) {
                $shop->forceFill(['ledger_enabled' => true])->saveQuietly();
                StartLedger::handle($shop);
                $shop->refresh();
            });
        }
    }

    protected function tearDown(): void
    {
        if (env('LEDGER_FORCE_ON') && Schema::hasTable('ledger_outbox')) {
            auth()->logout();
            app(LedgerPoster::class)->drain(limit: 100000);

            $problem = LedgerOutbox::whereNotNull('last_error')->value('last_error');
            $this->assertNull($problem, "Ledger posting failed: {$problem}");
            $this->assertSame(0, (int) DB::table('ledger_lines')->sum('amount_paise'), 'The ledger does not balance.');
        }

        parent::tearDown();
    }
}
