<?php

namespace App\Workflow\Nodes;

use App\Support\AccessRestrictions;
use App\Workflow\WorkflowContext;

class TimeWindowCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.time_window';
    }

    public function label(): string
    {
        return 'Uhrzeit im Zeitfenster';
    }

    public function description(): string
    {
        return 'Ja, wenn die aktuelle Uhrzeit in einem der Zeitfenster liegt.';
    }

    public function phases(): array
    {
        return ['login', 'authorize', 'event'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'windows', 'label' => 'Zeitfenster', 'type' => 'lines', 'default' => 'Mo-Fr 08:00-18:00', 'required' => true, 'help' => 'Ein Fenster pro Zeile, z. B. „Mo-Fr 08:00-18:00“ oder „Sa 09:00-12:00“.'],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return AccessRestrictions::timeAllowed(now(), implode("\n", self::lines($config['windows'] ?? '')));
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        if (AccessRestrictions::parseTimeWindows(implode("\n", self::lines($config['windows'] ?? ''))) === null) {
            $errors[] = 'Die Zeitfenster haben kein gültiges Format (z. B. „Mo-Fr 08:00-18:00“).';
        }

        return $errors;
    }
}
