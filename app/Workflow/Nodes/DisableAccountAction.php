<?php

namespace App\Workflow\Nodes;

use App\Services\SessionTracker;
use App\Workflow\WorkflowContext;

class DisableAccountAction extends ActionNode
{
    public function key(): string
    {
        return 'action.disable_account';
    }

    public function label(): string
    {
        return 'Konto deaktivieren';
    }

    public function description(): string
    {
        return 'Deaktiviert das betroffene Konto und beendet seine Sitzungen. Administratoren werden nie deaktiviert.';
    }

    protected function perform(array $config, WorkflowContext $context): void
    {
        $user = $context->user();

        if (! $user || $user->is_admin || ! $user->is_active) {
            return;
        }

        $user->forceFill(['is_active' => false])->save();
        app(SessionTracker::class)->revokeAllForUserExcept($user, null);

        $this->audit($context);
    }
}
