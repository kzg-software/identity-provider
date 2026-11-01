<?php

namespace App\Workflow;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bausteine mit eigener Seite unter /flow/continue (z. B. Hinweis,
 * Nutzungsbedingungen). Nach dem Absenden setzt der FlowController den
 * Merker „done:<Knoten-ID>“ bzw. „declined:<Knoten-ID>“ und der Workflow
 * läuft an diesem Baustein weiter.
 */
interface InteractiveNode
{
    public function render(array $config, WorkflowContext $context): View;

    /**
     * Verarbeitet das Formular. Rückgabe: 'done', 'declined' oder eine
     * Fehlermeldung (dann wird die Seite erneut gezeigt).
     */
    public function submit(array $config, WorkflowContext $context, Request $request): string;
}
