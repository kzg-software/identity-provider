<?php

namespace App\Console\Commands;

use App\Services\Backup\AutoBackupRunner;
use Illuminate\Console\Command;

class RunAutoBackup extends Command
{
    protected $signature = 'backup:run {--force : Auch ausführen, wenn die automatische Sicherung deaktiviert ist}';

    protected $description = 'Erstellt eine Sicherung und lädt sie an das konfigurierte Ziel hoch';

    public function handle(AutoBackupRunner $runner): int
    {
        if (! $this->option('force') && ! $runner->isDue()) {
            $this->info('Zurzeit ist keine automatische Sicherung fällig – übersprungen.');

            return self::SUCCESS;
        }

        $result = $runner->run();

        if (! ($result['ok'] ?? false)) {
            $this->error($result['message'] ?? 'Die Sicherung ist fehlgeschlagen.');

            return self::FAILURE;
        }

        $this->info("Sicherung {$result['file']} erstellt und hochgeladen.");

        if (($result['pruned'] ?? 0) > 0) {
            $this->info("{$result['pruned']} alte Sicherung(en) entfernt.");
        }

        return self::SUCCESS;
    }
}
