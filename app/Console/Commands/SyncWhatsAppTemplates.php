<?php

namespace App\Console\Commands;

use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Models\ShopChannelConnection;
use App\Services\WhatsApp\TemplateManager;
use Illuminate\Console\Command;
use Throwable;

class SyncWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Refresh approval status of WhatsApp message templates for every connected shop';

    public function handle(TemplateManager $templates): int
    {
        $count = 0;

        ShopChannelConnection::withoutGlobalScopes()
            ->where('provider', ChannelProvider::WhatsApp->value)
            ->where('status', ConnectionStatus::Connected->value)
            ->whereNotNull('waba_id')
            ->each(function (ShopChannelConnection $connection) use ($templates, &$count) {
                try {
                    $count += $templates->sync($connection);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Synced {$count} template(s).");

        return self::SUCCESS;
    }
}
