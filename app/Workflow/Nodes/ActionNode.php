<?php

namespace App\Workflow\Nodes;

use App\Models\AuditLog;
use App\Workflow\EventTriggers;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

/**
 * Basis für Aktionen in Ereignis-Workflows. Eine fehlgeschlagene Aktion
 * bricht den Ablauf nicht ab, sie wird nur protokolliert.
 */
abstract class ActionNode extends NodeType
{
    public function category(): string
    {
        return self::CATEGORY_ACTION;
    }

    public function phases(): array
    {
        return ['event'];
    }

    abstract protected function perform(array $config, WorkflowContext $context): void;

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        if (! $context->dryRun) {
            $this->perform($this->withDefaults($config), $context);
        }

        return NodeResult::next();
    }

    protected function audit(WorkflowContext $context, array $meta = []): void
    {
        AuditLog::record('workflow.action', $context->user(), array_merge(['action' => $this->key()], $meta), $context->application());
    }

    /**
     * Platzhalter in Texten: {benutzer}, {name}, {email}, {anwendung},
     * {ereignis}, {ip}, {zeit}.
     */
    protected static function fill(string $text, WorkflowContext $context): string
    {
        $user = $context->user();

        return strtr($text, [
            '{benutzer}' => (string) ($user?->username ?? ''),
            '{name}' => (string) ($user?->name ?? ''),
            '{email}' => (string) ($user?->email ?? ''),
            '{anwendung}' => (string) ($context->application()?->name ?? ''),
            '{ereignis}' => EventTriggers::label((string) ($context->data['event'] ?? '')),
            '{ip}' => (string) ($context->data['ip'] ?? ''),
            '{zeit}' => now()->format('d.m.Y H:i'),
        ]);
    }

    protected static function placeholderHelp(): string
    {
        return 'Platzhalter: {benutzer}, {name}, {email}, {anwendung}, {ereignis}, {ip}, {zeit}';
    }
}
