<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCompletion;
use App\Http\Controllers\Controller;
use App\Workflow\InteractiveNode;
use App\Workflow\WorkflowContext;
use App\Workflow\WorkflowRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Zeigt die Seite eines interaktiven Workflow-Bausteins (Hinweis,
 * Nutzungsbedingungen, …) und setzt den Workflow nach dem Absenden fort.
 * Beim Login ist der Nutzer dabei noch nicht angemeldet, beim Zugriff auf
 * eine Anwendung schon.
 */
class WorkflowFlowController extends Controller
{
    public function __construct(
        private WorkflowRunner $runner,
        private LoginCompletion $completion,
    ) {}

    public function show(): View|RedirectResponse
    {
        [$phase, $context, $node] = $this->pending();

        if (! $node) {
            return $this->expired($phase);
        }

        return $node['type']->render($node['config'], $context)->with('workflowPhase', $phase);
    }

    public function submit(Request $request): View|RedirectResponse
    {
        [$phase, $context, $node] = $this->pending();

        if (! $node) {
            return $this->expired($phase);
        }

        $outcome = $node['type']->submit($node['config'], $context, $request);

        if (! in_array($outcome, ['done', 'declined'], true)) {
            return back()->withErrors(['workflow' => $outcome]);
        }

        $flag = $outcome.':'.$context->currentNode;

        if ($phase === 'login') {
            $result = $this->runner->resume('login', fn (WorkflowContext $c) => $c->setFlag($flag));

            return $result ? $this->completion->conclude($request, $result) : $this->expired($phase);
        }

        // Authorize: Merker setzen, die Fortsetzung übernimmt der Protokoll-Controller.
        $this->runner->updatePending('authorize', fn (WorkflowContext $c) => $c->setFlag($flag));

        return redirect()->route($context->protocol === 'saml' ? 'saml.sso.continue' : 'oauth.authorize.resume');
    }

    /**
     * @return array{0: string|null, 1: WorkflowContext|null, 2: array{type: InteractiveNode, config: array}|null}
     */
    private function pending(): array
    {
        foreach (['login', 'authorize'] as $phase) {
            $context = $this->runner->pendingContext($phase);

            if (! $context) {
                continue;
            }

            // Beim Zugriff auf Anwendungen gehört der Lauf zum angemeldeten Konto.
            if ($phase === 'authorize' && (! Auth::check() || Auth::id() !== $context->userId)) {
                continue;
            }

            $node = $this->runner->pendingNode($phase);

            if ($node && $node['type'] instanceof InteractiveNode) {
                return [$phase, $context, $node];
            }

            return [$phase, $context, null];
        }

        return [null, null, null];
    }

    private function expired(?string $phase): RedirectResponse
    {
        if ($phase) {
            $this->runner->forget($phase);
        }

        return Auth::check()
            ? redirect()->route('dashboard')
            : redirect()->route('login')->withErrors(['username' => 'Die Anmeldung ist abgelaufen. Bitte erneut anmelden.']);
    }
}
