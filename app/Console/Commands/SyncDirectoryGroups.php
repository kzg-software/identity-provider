<?php

namespace App\Console\Commands;

use App\Directory\DirectorySyncService;
use App\Models\Directory;
use Illuminate\Console\Command;

class SyncDirectoryGroups extends Command
{
    protected $signature = 'directory:sync-groups {directory? : Optionale Directory-ID, sonst alle aktiven}';

    protected $description = 'Synchronisiert nur die Gruppen aller aktiven Verzeichnisse (schneller, häufigerer Lauf)';

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
            $result = $service->syncGroupsOnly($directory);

            if ($result['ok']) {
                $this->info("{$directory->name}: {$result['groups']} Gruppen synchronisiert.");
            } else {
                $this->error("{$directory->name}: {$result['message']}");
            }
        }

        return self::SUCCESS;
    }
}
