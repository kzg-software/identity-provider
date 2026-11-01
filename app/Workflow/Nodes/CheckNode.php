<?php

namespace App\Workflow\Nodes;

use App\Models\AuditLog;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

/**
 * Basis für die Prüfungen beim Öffnen einer Anwendung. Blockiert eine
 * Prüfung, schreibt sie das gleiche Audit-Ereignis wie bisher und merkt sich
 * Meldung und HTTP-Status für einen nachfolgenden „Ablehnen“-Baustein.
 */
abstract class CheckNode extends NodeType
{
    public function category(): string
    {
        return self::CATEGORY_CHECK;
    }

    public function phases(): array
    {
        return ['authorize'];
    }

    public function outputs(array $config): array
    {
        return ['ok' => 'ok', 'blockiert' => 'blockiert'];
    }

    protected function block(WorkflowContext $context, string $auditSuffix, array $meta, string $message, int $status = 403): NodeResult
    {
        AuditLog::record($context->auditEvent($auditSuffix), $context->user(), $context->auditMeta($meta), $context->application());
        $context->setDenial($message, $status);

        return NodeResult::next('blockiert');
    }
}
