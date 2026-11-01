<?php

namespace App\Workflow\Nodes;

use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

class StartNode extends NodeType
{
    public function key(): string
    {
        return 'start';
    }

    public function label(): string
    {
        return 'Start';
    }

    public function category(): string
    {
        return self::CATEGORY_FLOW;
    }

    public function description(): string
    {
        return 'Hier beginnt der Ablauf.';
    }

    public function isEntry(): bool
    {
        return true;
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        return NodeResult::next();
    }
}
