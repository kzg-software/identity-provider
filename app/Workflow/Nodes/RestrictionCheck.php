<?php

namespace App\Workflow\Nodes;

use App\Services\AccessPolicyEvaluator;
use App\Support\AccessRestrictions;
use App\Workflow\NodeResult;
use App\Workflow\WorkflowContext;

class RestrictionCheck extends CheckNode
{
    public function key(): string
    {
        return 'check.restrictions';
    }

    public function label(): string
    {
        return 'Netzwerk und Uhrzeit prüfen';
    }

    public function description(): string
    {
        return 'Blockiert außerhalb der bei der Anwendung hinterlegten Netze bzw. Zeitfenster.';
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        if ($violation = AccessPolicyEvaluator::restrictionViolation($context->applicationId)) {
            return $this->block($context, 'restricted', ['reason' => $violation], AccessRestrictions::message($violation));
        }

        return NodeResult::next('ok');
    }
}
