<?php

namespace App\Workflow\Nodes;

use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

/**
 * Basis für Ja/Nein-Verzweigungen.
 */
abstract class ConditionNode extends NodeType
{
    public function category(): string
    {
        return self::CATEGORY_CONDITION;
    }

    public function outputs(array $config): array
    {
        return ['ja' => 'ja', 'nein' => 'nein'];
    }

    abstract protected function matches(array $config, WorkflowContext $context): bool;

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        return NodeResult::next($this->matches($config, $context) ? 'ja' : 'nein');
    }
}
