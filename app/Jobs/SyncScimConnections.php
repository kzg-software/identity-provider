<?php

namespace App\Jobs;

use App\Models\ScimConnection;
use App\Scim\ScimSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncScimConnections implements ShouldQueue
{
    use Queueable;

    public function handle(ScimSyncService $service): void
    {
        Cache::forget('scim.sync_pending');

        foreach (ScimConnection::query()->where('is_active', true)->get() as $connection) {
            $service->sync($connection);
        }
    }
}
