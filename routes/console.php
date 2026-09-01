<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('directory:sync-groups')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('directory:sync-users')->daily()->withoutOverlapping();

// Prüft das GitHub-Repository auf neue Releases (Ergebnis wird in der
// Administration unter "Aktualisierungen" angezeigt).
Schedule::command('updates:check')->twiceDailyAt(6, 18, 15)->withoutOverlapping();

// Beweist auf der Systemstatus-Seite, dass der Laravel-Scheduler tatsächlich läuft.
Schedule::call(fn () => Cache::put('schedule.heartbeat', now()->toDateTimeString(), 600))
    ->everyMinute()
    ->name('schedule-heartbeat')
    ->withoutOverlapping();
