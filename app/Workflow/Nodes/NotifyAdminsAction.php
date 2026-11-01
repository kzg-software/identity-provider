<?php

namespace App\Workflow\Nodes;

use App\Support\Notifier;
use App\Workflow\WorkflowContext;

class NotifyAdminsAction extends ActionNode
{
    public function key(): string
    {
        return 'action.notify_admins';
    }

    public function label(): string
    {
        return 'Administratoren benachrichtigen';
    }

    public function description(): string
    {
        return 'Benachrichtigung an alle aktiven Administratoren (Glocke, je nach Einstellung auch per E-Mail).';
    }

    public function fields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel', 'type' => 'text', 'default' => '{ereignis}: {benutzer}', 'required' => true, 'help' => self::placeholderHelp()],
            ['key' => 'body', 'label' => 'Text', 'type' => 'textarea', 'default' => "Konto: {name} ({benutzer})\nAnwendung: {anwendung}\nIP: {ip}\nZeit: {zeit}"],
        ];
    }

    protected function perform(array $config, WorkflowContext $context): void
    {
        Notifier::toAdmins('system.logins', self::fill($config['title'], $context), [
            'level' => 'warning',
            'body' => self::fill((string) $config['body'], $context) ?: null,
        ]);

        $this->audit($context);
    }
}
