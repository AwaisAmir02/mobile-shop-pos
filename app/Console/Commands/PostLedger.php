<?php

namespace App\Console\Commands;

use App\Ledger\LedgerPoster;
use Illuminate\Console\Command;

class PostLedger extends Command
{
    protected $signature = 'ledger:post {--limit=500}';

    protected $description = 'Post changed documents to the ledger';

    public function handle(LedgerPoster $poster): int
    {
        $this->info($poster->drain(null, (int) $this->option('limit')).' posted.');

        return self::SUCCESS;
    }
}
