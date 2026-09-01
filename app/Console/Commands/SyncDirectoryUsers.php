<?php

namespace App\Console\Commands;

use App\Directory\DirectorySyncService;
use App\Models\Directory;
use Illuminate\Console\Command;

class SyncDirectoryUsers extends Command
{
    protected $signature = 'directory:sync-users {directory? : Optionale Directory-ID, sonst alle aktiven}';

    protected $description = 'Synchronisiert Benutzer (und Gruppen) aus allen aktiven Verzeichnissen';

    public function handle(DirectorySyncService $service): int
    {
        $directories = $this->argument('directory')
            ? Directory::where('id', $this->argument('directory'))->get()
            : Directory::where('is_active', true)->get();

        if ($directories->isEmpty()) {
            $this->warn('Keine aktiven Verzeichnisse gefunden.');

            return self::SUCCESS;
        }

        foreach ($directories as $directory) {
            $this->info("Synchronisiere {$directory->name}...");
            $result = $service->syncAll($directory);

            if ($result['ok']) {
                $this->info("  OK: {$result['users']} Benutzer, {$result['groups']} Gruppen, {$result['duration']}s");
            } else {
                $this->error("  Fehler: {$result['message']}");
            }
        }

        return self::SUCCESS;
    }
}
