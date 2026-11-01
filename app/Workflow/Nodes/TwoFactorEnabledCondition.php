<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class TwoFactorEnabledCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.two_factor_enabled';
    }

    public function label(): string
    {
        return 'Zwei-Faktor eingerichtet';
    }

    public function description(): string
    {
        return 'Ja, wenn das Konto eine Authenticator-App oder einen Passkey als zweiten Faktor hat.';
    }

    public function phases(): array
    {
        return ['login', 'authorize', 'event'];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return (bool) $context->user()?->hasTwoFactorEnabled();
    }
}
