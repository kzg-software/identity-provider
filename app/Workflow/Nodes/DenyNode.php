<?php

namespace App\Workflow\Nodes;

use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

class DenyNode extends NodeType
{
    public function key(): string
    {
        return 'deny';
    }

    public function label(): string
    {
        return 'Ablehnen';
    }

    public function category(): string
    {
        return self::CATEGORY_FLOW;
    }

    public function description(): string
    {
        return 'Beendet den Ablauf mit einer Fehlermeldung. Ohne eigenen Text wird die Meldung der vorherigen Prüfung angezeigt.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'message', 'label' => 'Meldung', 'type' => 'textarea', 'default' => '', 'help' => 'Leer lassen, um die Meldung der vorherigen Prüfung zu verwenden.'],
        ];
    }

    public function outputs(array $config): array
    {
        return [];
    }

    public function isTerminal(): bool
    {
        return true;
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $denial = $context->denial();
        $message = trim((string) ($config['message'] ?? '')) ?: ($denial['message'] ?? self::defaultMessage($context));

        return NodeResult::deny($message, $denial['status'] ?? 403);
    }

    private static function defaultMessage(WorkflowContext $context): string
    {
        return $context->phase === 'login'
            ? 'Die Anmeldung ist für dieses Konto nicht erlaubt.'
            : 'Sie sind nicht berechtigt, diese Anwendung zu nutzen.';
    }
}
