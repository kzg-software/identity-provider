<?php

namespace App\Console\Commands;

use App\Models\ScimConnection;
use App\Scim\ScimSyncService;
use Illuminate\Console\Command;

class SyncScim extends Command
{
    protected $signature = 'scim:sync {--application= : Nur die Anwendung mit dieser ID synchronisieren}';

    protected $description = 'Gleicht Benutzer und Gruppen per SCIM mit den Zielanwendungen ab';

    public function handle(ScimSyncService $service): int
    {
        $connections = ScimConnection::query()
            ->where('is_active', true)
            ->when($this->option('application'), fn ($query, $id) => $query->where('application_id', $id))
            ->with('application')
            ->get();

        $failed = false;

        foreach ($connections as $connection) {
            $stats = $service->sync($connection);
            $failed = $failed || $stats['errors'] !== [];

            $this->line(sprintf(
                '%s: %d angelegt, %d aktualisiert, %d entfernt, %d Fehler',
                $connection->application?->name ?? '#'.$connection->application_id,
                $stats['created'],
                $stats['updated'],
                $stats['removed'],
                count($stats['errors']),
            ));

            foreach ($stats['errors'] as $error) {
                $this->warn('  '.$error);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
