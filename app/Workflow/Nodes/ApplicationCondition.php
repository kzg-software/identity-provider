<?php

namespace App\Workflow\Nodes;

use App\Models\Application;
use App\Workflow\WorkflowContext;

class ApplicationCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.application';
    }

    public function label(): string
    {
        return 'Anwendung ist';
    }

    public function description(): string
    {
        return 'Ja, wenn der Vorgang zu einer der Anwendungen gehört. Beim Login ist das die Anwendung, zu der man nach der Anmeldung weitergeleitet wird.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'applications', 'label' => 'Anwendungen', 'type' => 'multiselect', 'default' => [], 'required' => true,
                'options' => Application::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return $context->applicationId !== null
            && in_array((string) $context->applicationId, array_map('strval', (array) ($config['applications'] ?? [])), true);
    }
}
