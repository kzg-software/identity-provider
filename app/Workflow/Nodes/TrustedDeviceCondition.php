<?php

namespace App\Workflow\Nodes;

use App\Auth\TrustedDevices;
use App\Workflow\WorkflowContext;

class TrustedDeviceCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.trusted_device';
    }

    public function label(): string
    {
        return 'Gerät ist vertraut';
    }

    public function description(): string
    {
        return 'Ja, wenn der Nutzer diesem Gerät bei einer früheren Zwei-Faktor-Abfrage vertraut hat.';
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        $user = $context->user();

        // Im Testlauf gibt es kein Gerät; „isTrusted“ würde außerdem den Eintrag aktualisieren.
        return $user !== null && ! $context->dryRun && app(TrustedDevices::class)->isTrusted($user, request());
    }
}
