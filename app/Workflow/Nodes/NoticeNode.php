<?php

namespace App\Workflow\Nodes;

use App\Models\WorkflowAcceptance;
use App\Workflow\InteractiveNode;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NoticeNode extends NodeType implements InteractiveNode
{
    public function key(): string
    {
        return 'notice';
    }

    public function label(): string
    {
        return 'Hinweis anzeigen';
    }

    public function category(): string
    {
        return self::CATEGORY_INTERACTION;
    }

    public function description(): string
    {
        return 'Zeigt eine Seite mit Titel und Text, die mit „Weiter“ bestätigt wird.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel', 'type' => 'text', 'default' => 'Hinweis', 'required' => true],
            ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => '', 'required' => true],
            ['key' => 'button', 'label' => 'Beschriftung des Buttons', 'type' => 'text', 'default' => 'Weiter'],
            ['key' => 'once', 'label' => 'Nur einmal pro Konto anzeigen', 'type' => 'checkbox', 'default' => false],
        ];
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $config = $this->withDefaults($config);

        if ($context->flag('done:'.$context->currentNode)) {
            return NodeResult::next();
        }

        if ($config['once'] && $context->userId && WorkflowAcceptance::has($context->userId, $this->documentKey($config), '1')) {
            return NodeResult::next();
        }

        return NodeResult::interact('page');
    }

    public function render(array $config, WorkflowContext $context): View
    {
        return view('workflow.notice', ['config' => $this->withDefaults($config), 'context' => $context]);
    }

    public function submit(array $config, WorkflowContext $context, Request $request): string
    {
        $config = $this->withDefaults($config);

        if ($config['once'] && $context->userId) {
            WorkflowAcceptance::record($context->userId, $this->documentKey($config), '1');
        }

        return 'done';
    }

    private function documentKey(array $config): string
    {
        return 'notice:'.substr(sha1($config['title'].'|'.$config['text']), 0, 16);
    }
}
