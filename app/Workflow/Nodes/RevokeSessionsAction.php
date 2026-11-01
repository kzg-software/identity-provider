<?php

namespace App\Workflow\Nodes;

use App\Services\SessionTracker;
use App\Workflow\WorkflowContext;

class RevokeSessionsAction extends ActionNode
{
    public function key(): string
    {
        return 'action.revoke_sessions';
    }

    public function label(): string
    {
        return 'Sitzungen beenden';
    }

    public function description(): string
    {
        return 'Meldet das betroffene Konto überall ab, inklusive Single Logout bei den Anwendungen.';
    }

    protected function perform(array $config, WorkflowContext $context): void
    {
        if ($user = $context->user()) {
            app(SessionTracker::class)->revokeAllForUserExcept($user, null);
            $this->audit($context);
        }
    }
}
