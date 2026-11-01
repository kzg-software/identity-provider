<?php

namespace App\Workflow\Nodes;

use App\Models\AuditLog;
use App\Models\WorkflowAcceptance;
use App\Workflow\InteractiveNode;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TermsNode extends NodeType implements InteractiveNode
{
    public function key(): string
    {
        return 'terms';
    }

    public function label(): string
    {
        return 'Nutzungsbedingungen';
    }

    public function category(): string
    {
        return self::CATEGORY_INTERACTION;
    }

    public function description(): string
    {
        return 'Verlangt die Zustimmung zu einem Text. Jede Version wird pro Konto einmal abgefragt; eine neue Version fragt erneut.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel', 'type' => 'text', 'default' => 'Nutzungsbedingungen', 'required' => true],
            ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => '', 'required' => true],
            ['key' => 'document', 'label' => 'Kennung', 'type' => 'text', 'default' => 'nutzungsbedingungen', 'required' => true, 'help' => 'Gleiche Kennung in mehreren Workflows = Zustimmung gilt überall.'],
            ['key' => 'version', 'label' => 'Version', 'type' => 'text', 'default' => '1', 'required' => true, 'help' => 'Erhöhen, um alle erneut zustimmen zu lassen.'],
        ];
    }

    public function outputs(array $config): array
    {
        return ['akzeptiert' => 'akzeptiert', 'abgelehnt' => 'abgelehnt'];
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $config = $this->withDefaults($config);

        if ($context->flag('declined:'.$context->currentNode)) {
            return NodeResult::next('abgelehnt');
        }

        if ($context->flag('done:'.$context->currentNode)
            || ($context->userId && WorkflowAcceptance::has($context->userId, $config['document'], (string) $config['version']))) {
            return NodeResult::next('akzeptiert');
        }

        return NodeResult::interact('page');
    }

    public function render(array $config, WorkflowContext $context): View
    {
        return view('workflow.terms', ['config' => $this->withDefaults($config), 'context' => $context]);
    }

    public function submit(array $config, WorkflowContext $context, Request $request): string
    {
        $config = $this->withDefaults($config);

        if ($request->input('decision') === 'decline') {
            AuditLog::record('workflow.terms_declined', $context->user(), ['document' => $config['document'], 'version' => (string) $config['version']], $context->application());

            return 'declined';
        }

        if (! $request->boolean('accept')) {
            return 'Bitte bestätige, dass du die Bedingungen gelesen hast und akzeptierst.';
        }

        WorkflowAcceptance::record($context->userId, $config['document'], (string) $config['version']);
        AuditLog::record('workflow.terms_accepted', $context->user(), ['document' => $config['document'], 'version' => (string) $config['version']], $context->application());

        return 'done';
    }
}
