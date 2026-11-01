<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class AdminCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.admin';
    }

    public function label(): string
    {
        return 'Ist Administrator';
    }

    public function description(): string
    {
        return 'Ja, wenn das Konto Administratorrechte hat.';
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return (bool) $context->user()?->is_admin;
    }
}
