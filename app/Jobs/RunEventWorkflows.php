<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Workflow\EventTriggers;
use App\Workflow\WorkflowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunEventWorkflows implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $auditLogId) {}

    public function handle(WorkflowRunner $runner): void
    {
        $log = AuditLog::query()->find($this->auditLogId);

        if ($log) {
            EventTriggers::run($log, $runner);
        }
    }
}
