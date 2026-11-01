<?php

namespace App\Workflow\Nodes;

use App\Services\AccessPolicyEvaluator;
use App\Workflow\NodeResult;
use App\Workflow\WorkflowContext;

class PolicyCheck extends CheckNode
{
    public function key(): string
    {
        return 'check.policies';
    }

    public function label(): string
    {
        return 'Zugriffsrichtlinien prüfen';
    }

    public function description(): string
    {
        return 'Blockiert, wenn die Zugriffsrichtlinien der Anwendung das Konto nicht zulassen.';
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $user = $context->user();

        if (! $user || ! AccessPolicyEvaluator::passesPolicies($context->applicationId, $user)) {
            return $this->block($context, 'denied', [], 'Sie sind nicht berechtigt, diese Anwendung zu nutzen.');
        }

        return NodeResult::next('ok');
    }
}
