<?php

namespace App\Workflow\Nodes;

use App\Models\UserLoginDevice;
use App\Services\SessionTracker;
use App\Workflow\WorkflowContext;

class NewDeviceCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.new_device';
    }

    public function label(): string
    {
        return 'Neues Gerät';
    }

    public function description(): string
    {
        return 'Ja, wenn sich das Konto zum ersten Mal mit diesem Browser/Gerät anmeldet.';
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        if ($context->userId === null) {
            return false;
        }

        return ! UserLoginDevice::query()
            ->where('user_id', $context->userId)
            ->where('signature', SessionTracker::deviceSignature(request()->userAgent()))
            ->exists();
    }
}
