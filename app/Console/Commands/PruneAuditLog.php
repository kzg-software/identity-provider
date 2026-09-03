<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

class PruneAuditLog extends Command
{
    protected $signature = 'audit-log:prune {--days= : Aufbewahrungsfrist in Tagen (überschreibt die Systemeinstellung)}';

    protected $description = 'Löscht Audit-Log-Einträge, die älter sind als die konfigurierte Aufbewahrungsfrist';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? SystemSetting::get('audit_log_retention_days'));

        if ($days <= 0) {
            $this->info('Keine Aufbewahrungsfrist gesetzt – es wird nichts gelöscht.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = AuditLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("{$deleted} Audit-Log-Einträge vor {$cutoff->toDateString()} gelöscht.");

        return self::SUCCESS;
    }
}
