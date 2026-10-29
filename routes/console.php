<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('whatsapp:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
Schedule::command('whatsapp:follow-ups')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('whatsapp:sync-templates')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('marketing:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('marketing:sync-insights --stories-only')->hourly()->withoutOverlapping();
Schedule::command('marketing:sync-insights')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('marketing:sync-ads')->everyThreeHours()->withoutOverlapping();
Schedule::command('billing:expire-addons')->hourly()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:30')->timezone('Asia/Karachi')->withoutOverlapping(120);
Schedule::command('backup:monitor')->hourly()->withoutOverlapping();
