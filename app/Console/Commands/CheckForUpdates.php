<?php

namespace App\Console\Commands;

use App\Services\UpdateChecker;
use Illuminate\Console\Command;

class CheckForUpdates extends Command
{
    protected $signature = 'updates:check {--force : Auch prüfen, wenn das letzte Ergebnis noch frisch ist}';

    protected $description = 'Prüft das GitHub-Repository auf ein neueres veröffentlichtes Release';

    public function handle(): int
    {
        if (! UpdateChecker::enabled()) {
            $this->error('Kein Ziel-Repository konfiguriert (config/updates.php).');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! UpdateChecker::isStale()) {
            $this->info('Letztes Ergebnis ist noch frisch – übersprungen (--force zum Erzwingen).');

            return self::SUCCESS;
        }

        $status = UpdateChecker::refresh();

        if ($status['error']) {
            $this->error('Prüfung fehlgeschlagen: '.$status['error']);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Installiert: <info>%s</info>   Neueste: <info>%s</info>   Update verfügbar: <info>%s</info>',
            $status['current'],
            $status['latest'] ?? 'unbekannt',
            $status['update_available'] ? 'ja' : 'nein',
        ));

        return self::SUCCESS;
    }
}
