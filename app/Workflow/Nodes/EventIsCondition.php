<?php

namespace App\Workflow\Nodes;

use App\Workflow\EventTriggers;
use App\Workflow\WorkflowContext;

class EventIsCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.event';
    }

    public function label(): string
    {
        return 'Ereignis ist';
    }

    public function description(): string
    {
        return 'Ja, wenn das auslösende Ereignis eines der gewählten ist.';
    }

    public function phases(): array
    {
        return ['event'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'events', 'label' => 'Ereignisse', 'type' => 'multiselect', 'default' => [], 'required' => true, 'options' => EventTriggers::EVENTS],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return in_array($context->data['event'] ?? null, (array) ($config['events'] ?? []), true);
    }
}
