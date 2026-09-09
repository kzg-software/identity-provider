<?php

namespace App\Console\Commands;

use App\Support\SystemNotifications;
use Illuminate\Console\Command;

class SyncNotifications extends Command
{
    protected $signature = 'notifications:sync';

    protected $description = 'Erzeugt Benachrichtigungen für Administratoren aus dem aktuellen Systemzustand und räumt erledigte auf';

    public function handle(): int
    {
        SystemNotifications::sync();

        $this->info('Benachrichtigungen abgeglichen.');

        return self::SUCCESS;
    }
}
