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

// Räumt den Audit-Log gemäß der in den Systemeinstellungen gesetzten
// Aufbewahrungsfrist auf (ohne Frist passiert nichts).
Schedule::command('audit-log:prune')->dailyAt('03:20')->withoutOverlapping();

// Prüft das GitHub-Repository auf neue Releases (Ergebnis wird in der
// Administration unter "Aktualisierungen" angezeigt).
Schedule::command('updates:check --force')->everyTwoHours()->withoutOverlapping();

// Beweist auf der Systemstatus-Seite, dass der Laravel-Scheduler tatsächlich läuft.
Schedule::call(fn () => Cache::put('schedule.heartbeat', now()->toDateTimeString(), 600))
    ->everyMinute()
    ->name('schedule-heartbeat')
    ->withoutOverlapping();
