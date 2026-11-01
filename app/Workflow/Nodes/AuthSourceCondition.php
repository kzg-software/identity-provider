<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class AuthSourceCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.auth_source';
    }

    public function label(): string
    {
        return 'Kontoquelle ist';
    }

    public function description(): string
    {
        return 'Ja, wenn das Konto lokal bzw. aus dem Verzeichnis (Active Directory) stammt.';
    }

    public function phases(): array
    {
        return ['login', 'authorize', 'event'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'source', 'label' => 'Quelle', 'type' => 'select', 'default' => 'local', 'options' => ['local' => 'Lokales Konto', 'active_directory' => 'Active Directory']],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        $source = $context->user()?->auth_source;
        $wanted = $config['source'] ?? 'local';

        return $wanted === 'local' ? $source === 'local' : $source !== null && $source !== 'local';
    }
}
