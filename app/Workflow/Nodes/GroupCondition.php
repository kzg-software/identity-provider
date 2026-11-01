<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class GroupCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.group';
    }

    public function label(): string
    {
        return 'Ist in Gruppe';
    }

    public function description(): string
    {
        return 'Ja, wenn das Konto Mitglied mindestens einer der Gruppen ist.';
    }

    public function phases(): array
    {
        return ['login', 'authorize', 'event'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'groups', 'label' => 'Gruppen', 'type' => 'lines', 'default' => '', 'required' => true, 'help' => 'Eine Gruppe pro Zeile, exakter Gruppenname.'],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        $groups = array_map('mb_strtolower', $context->user()?->groupNames() ?? []);

        foreach (self::lines($config['groups'] ?? '') as $group) {
            if (in_array(mb_strtolower($group), $groups, true)) {
                return true;
            }
        }

        return false;
    }
}
