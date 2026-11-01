<?php

namespace App\Workflow\Nodes;

use App\Workflow\EventTriggers;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

class TriggerNode extends NodeType
{
    public function key(): string
    {
        return 'trigger';
    }

    public function label(): string
    {
        return 'Auslöser';
    }

    public function category(): string
    {
        return self::CATEGORY_FLOW;
    }

    public function description(): string
    {
        return 'Startet den Ablauf, sobald eines der Ereignisse eintritt. Läuft im Hintergrund und kann den Vorgang selbst nicht mehr verhindern.';
    }

    public function phases(): array
    {
        return ['event'];
    }

    public function isEntry(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            ['key' => 'events', 'label' => 'Ereignisse', 'type' => 'multiselect', 'default' => [], 'required' => true, 'options' => EventTriggers::EVENTS],
        ];
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        return NodeResult::next();
    }
}
