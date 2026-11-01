<?php

namespace App\Workflow\Nodes;

use App\Support\Notifier;
use App\Workflow\WorkflowContext;

class NotifyUserAction extends ActionNode
{
    public function key(): string
    {
        return 'action.notify_user';
    }

    public function label(): string
    {
        return 'Nutzer benachrichtigen';
    }

    public function description(): string
    {
        return 'Benachrichtigung in der Glocke des betroffenen Kontos, je nach Einstellung auch per E-Mail.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel', 'type' => 'text', 'default' => '', 'required' => true, 'help' => self::placeholderHelp()],
            ['key' => 'body', 'label' => 'Text', 'type' => 'textarea', 'default' => ''],
            ['key' => 'security', 'label' => 'Sicherheitsrelevant (immer auch per E-Mail)', 'type' => 'checkbox', 'default' => false],
        ];
    }

    protected function perform(array $config, WorkflowContext $context): void
    {
        $user = $context->user();

        if (! $user) {
            return;
        }

        Notifier::toUser($user, $config['security'] ? 'security.workflow' : 'workflow.notice', self::fill($config['title'], $context), [
            'level' => $config['security'] ? 'warning' : 'info',
            'security' => (bool) $config['security'],
            'body' => self::fill((string) $config['body'], $context) ?: null,
        ]);

        $this->audit($context);
    }
}
