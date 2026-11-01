<?php

namespace App\Workflow\Nodes;

use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

class AllowNode extends NodeType
{
    public function key(): string
    {
        return 'allow';
    }

    public function label(): string
    {
        return 'Erlauben';
    }

    public function category(): string
    {
        return self::CATEGORY_FLOW;
    }

    public function description(): string
    {
        return 'Beendet den Ablauf erfolgreich: Anmeldung abschließen bzw. Zugriff gewähren.';
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
