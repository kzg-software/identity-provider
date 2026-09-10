<?php

namespace App\Console\Commands;

use App\Directory\DirectorySyncService;
use App\Models\Directory;
use Illuminate\Console\Command;

class SyncDirectoryDelta extends Command
{
    protected $signature = 'directory:sync-delta {directory? : Optionale Directory-ID, sonst alle aktiven mit aktivierter Delta-Synchronisierung}';

    protected $description = 'Inkrementelle Synchronisierung (nur seit dem letzten Lauf geänderte Objekte) für Active-Directory-Verzeichnisse';

    public function handle(DirectorySyncService $service): int
    {
        $directories = $this->argument('directory')
            ? Directory::where('id', $this->argument('directory'))->get()
            : Directory::where('is_active', true)->where('delta_sync_enabled', true)->get();

        if ($directories->isEmpty()) {
            $this->info('Kein Verzeichnis mit aktivierter Delta-Synchronisierung.');

            return self::SUCCESS;
        }

        foreach ($directories as $directory) {
            $this->info("Delta-Synchronisierung {$directory->name}...");
            $result = $service->syncDelta($directory);

            if ($result['ok']) {
                $mode = $result['mode'] ?? 'delta';
                $this->info("  OK ({$mode}): {$result['users']} Benutzer, {$result['groups']} Gruppen, {$result['duration']}s");
            } else {
                $this->error("  Fehler: {$result['message']}");
            }
        }

        return self::SUCCESS;
    }
}
