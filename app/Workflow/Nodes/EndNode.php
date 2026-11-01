<?php

namespace App\Workflow\Nodes;

use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

class EndNode extends NodeType
{
    public function key(): string
    {
        return 'end';
    }

    public function label(): string
    {
        return 'Ende';
    }

    public function category(): string
    {
        return self::CATEGORY_FLOW;
    }

    public function description(): string
    {
        return 'Beendet den Ereignis-Ablauf.';
    }

    public function phases(): array
    {
        return ['event'];
    }

    public function outputs(array $config): array
    {
        return [];
    }

    public function isTerminal(): bool
    {
        return true;
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        return NodeResult::allow();
    }
}
