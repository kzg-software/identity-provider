<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class SecondFactorUsedCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.second_factor_used';
    }

    public function label(): string
    {
        return 'Mit zweitem Faktor angemeldet';
    }

    public function description(): string
    {
        return 'Ja, wenn die aktuelle Anmeldung einen zweiten Faktor enthielt (oder passwortlos per Passkey erfolgte).';
    }

    public function phases(): array
    {
        return ['authorize'];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return str_contains($context->method, '+') || MethodCondition::primary($context->method) === 'passkey';
    }
}
