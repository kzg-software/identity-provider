<?php

namespace App\Workflow\Nodes;

use App\Support\MaintenanceGate;
use App\Workflow\NodeResult;
use App\Workflow\WorkflowContext;

class MaintenanceCheck extends CheckNode
{
    public function key(): string
    {
        return 'check.maintenance';
    }

    public function label(): string
    {
        return 'Wartung prüfen';
    }

    public function description(): string
    {
        return 'Blockiert, wenn die Anwendung im Wartungsmodus ist und das Konto nicht ausgenommen ist.';
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $application = $context->application();

        if ($application && MaintenanceGate::applicationBlockedFor($application, $context->user())) {
            return $this->block($context, 'maintenance', [], MaintenanceGate::applicationMessage($application), 503);
        }

        return NodeResult::next('ok');
    }
}
