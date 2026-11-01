<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class RoleCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.role';
    }

    public function label(): string
    {
        return 'Hat Rolle';
    }

    public function description(): string
    {
        return 'Ja, wenn das Konto mindestens eine der Rollen hat (aus Gruppen-Mapping oder manuell vergeben).';
    }

    public function fields(): array
    {
        return [
            ['key' => 'roles', 'label' => 'Rollen', 'type' => 'lines', 'default' => '', 'required' => true, 'help' => 'Eine Rolle pro Zeile.'],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        $roles = $context->user()?->effectiveRoles() ?? [];

        return array_intersect(self::lines($config['roles'] ?? ''), $roles) !== [];
    }
}
