<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Workflow\DefaultWorkflows;
use App\Workflow\EventTriggers;
use App\Workflow\NodeRegistry;
use App\Workflow\Nodes\MethodCondition;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;
use App\Workflow\WorkflowResolver;
use App\Workflow\WorkflowRunner;
use App\Workflow\WorkflowValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly WorkflowValidator $validator,
    ) {}

    public function index(): View
    {
        return view('admin.workflows.index', [
            'globals' => [
                WorkflowResolver::global(Workflow::PHASE_LOGIN),
                WorkflowResolver::global(Workflow::PHASE_AUTHORIZE),
            ],
            'overrides' => Workflow::query()->whereNotNull('application_id')->where('phase', '!=', Workflow::PHASE_EVENT)
                ->with('application')->orderBy('phase')->get(),
            'events' => Workflow::query()->where('phase', Workflow::PHASE_EVENT)->with('editor')->orderBy('name')->get(),
        ]);
    }

    public function edit(Workflow $workflow): View
    {
        $types = collect($this->registry->forPhase($workflow->phase))
            ->map(fn (NodeType $type) => $type->toArray())
            ->values();

        return view('admin.workflows.edit', [
            'workflow' => $workflow->load('application', 'editor'),
            'types' => $types,
            'versions' => $workflow->versions()->with('author')->limit(20)->get(),
            'applications' => Application::query()->orderBy('name')->get(['id', 'name']),
            'isGlobal' => $this->isGlobal($workflow),
        ]);
    }

    /**
     * Speichern aus dem Editor (JSON). Ungültige Graphen werden mit den
     * Fehlern je Baustein abgelehnt und nicht gespeichert.
     */
    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'graph' => 'required|array',
            'graph.nodes' => 'present|array',
            'graph.edges' => 'present|array',
        ]);

        $graph = $this->normalize($data['graph']);
        $result = $this->validator->validate($workflow->phase, $graph);

        if (! WorkflowValidator::passes($result)) {
            return response()->json(['ok' => false, 'errors' => $result], 422);
        }

        $workflow->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            // Der globale Login-/Authorize-Workflow ist immer aktiv.
            'is_active' => $this->isGlobal($workflow) ? true : (bool) ($data['is_active'] ?? $workflow->is_active),
        ]);
        $workflow->replaceGraph($graph, $request->user());

        AuditLog::record('admin.workflow_updated', $request->user(), [
            'workflow' => $workflow->name,
            'phase' => $workflow->phase,
            'version' => $workflow->version,
        ], $workflow->application);

        return response()->json(['ok' => true, 'version' => $workflow->version]);
    }

    /**
     * Testlauf des (ungespeicherten) Graphen aus dem Editor für ein Konto,
     * ohne Seiteneffekte.
     */
    public function simulate(Request $request, Workflow $workflow, WorkflowRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'graph' => 'required|array',
            'graph.nodes' => 'present|array',
            'graph.edges' => 'present|array',
            'username' => 'required|string',
            'application_id' => 'nullable|integer|exists:applications,id',
            'ip' => 'nullable|ip',
            'time' => 'nullable|date',
            'method' => ['nullable', Rule::in(array_keys(MethodCondition::METHODS))],
            'event' => ['nullable', Rule::in(array_keys(EventTriggers::EVENTS))],
        ]);

        $user = User::query()->where('username', $data['username'])->orWhere('email', $data['username'])->first();

        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Kein Konto mit diesem Benutzernamen gefunden.'], 422);
        }

        $applicationId = $workflow->application_id ?? ($data['application_id'] ?? null);
        $application = $applicationId ? Application::query()->find($applicationId) : null;

        $context = new WorkflowContext(
            $workflow->phase,
            $user->id,
            $application?->id,
            $data['method'] ?? 'local',
            $application?->provider?->type === 'saml' ? 'saml' : 'oidc',
            [],
            [
                'ip' => $data['ip'] ?? $request->ip(),
                'now' => $data['time'] ?? now()->toIso8601String(),
                'event' => $data['event'] ?? null,
                'scopes' => ['openid'],
            ],
        );

        $draft = (new Workflow)->forceFill(['phase' => $workflow->phase, 'name' => $workflow->name, 'graph' => $this->normalize($data['graph'])]);

        return response()->json(['ok' => true] + $runner->simulate($draft, $context));
    }

    public function storeEvent(Request $request): RedirectResponse
    {
        $workflow = Workflow::create([
            'phase' => Workflow::PHASE_EVENT,
            'name' => 'Neuer Ereignis-Workflow',
            'is_active' => false,
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'config' => ['events' => ['login.failed']], 'position' => ['x' => 40, 'y' => 120]],
                    ['id' => 'notify', 'type' => 'action.notify_admins', 'config' => (object) [], 'position' => ['x' => 320, 'y' => 100]],
                    ['id' => 'end', 'type' => 'end', 'config' => (object) [], 'position' => ['x' => 640, 'y' => 120]],
                ],
                'edges' => [
                    ['from' => 'trigger', 'output' => 'weiter', 'to' => 'notify'],
                    ['from' => 'notify', 'output' => 'weiter', 'to' => 'end'],
                ],
            ],
            'version' => 1,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.workflows.edit', $workflow);
    }

    /** Eigener Login-/Authorize-Workflow für eine Anwendung, als Kopie des globalen. */
    public function storeForApplication(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate(['phase' => ['required', Rule::in([Workflow::PHASE_LOGIN, Workflow::PHASE_AUTHORIZE])]]);

        $existing = Workflow::query()->where('phase', $data['phase'])->where('application_id', $application->id)->first();

        if ($existing) {
            return redirect()->route('admin.workflows.edit', $existing);
        }

        $global = WorkflowResolver::global($data['phase']);

        $workflow = Workflow::create([
            'phase' => $data['phase'],
            'application_id' => $application->id,
            'name' => Workflow::phaseLabel($data['phase']).' für '.$application->name,
            'is_active' => true,
            'graph' => $global->graph,
            'version' => 1,
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::record('admin.workflow_updated', $request->user(), ['workflow' => $workflow->name, 'phase' => $workflow->phase, 'created' => true], $application);

        return redirect()->route('admin.workflows.edit', $workflow);
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        abort_if($this->isGlobal($workflow), 403, 'Der globale Workflow kann nicht gelöscht werden.');

        AuditLog::record('admin.workflow_updated', $request->user(), ['workflow' => $workflow->name, 'phase' => $workflow->phase, 'deleted' => true], $workflow->application);
        $workflow->delete();

        return redirect()->route('admin.workflows.index')->with('status', 'Workflow gelöscht.');
    }

    /** Auf den Standard zurücksetzen (global) bzw. auf eine Kopie des globalen (Anwendung). */
    public function reset(Request $request, Workflow $workflow): RedirectResponse
    {
        abort_if($workflow->phase === Workflow::PHASE_EVENT, 404);

        $graph = $workflow->application_id
            ? WorkflowResolver::global($workflow->phase)->graph
            : DefaultWorkflows::graph($workflow->phase);

        $workflow->replaceGraph($graph, $request->user());
        AuditLog::record('admin.workflow_updated', $request->user(), ['workflow' => $workflow->name, 'phase' => $workflow->phase, 'reset' => true], $workflow->application);

        return redirect()->route('admin.workflows.edit', $workflow)->with('status', 'Workflow wurde zurückgesetzt.');
    }

    public function restore(Request $request, Workflow $workflow, WorkflowVersion $version): RedirectResponse
    {
        abort_unless($version->workflow_id === $workflow->id, 404);

        $result = $this->validator->validate($workflow->phase, $version->graph);
        abort_unless(WorkflowValidator::passes($result), 422, 'Diese Version ist mit den aktuellen Bausteinen nicht mehr gültig.');

        $workflow->replaceGraph($version->graph, $request->user());
        AuditLog::record('admin.workflow_updated', $request->user(), ['workflow' => $workflow->name, 'phase' => $workflow->phase, 'restored_version' => $version->version], $workflow->application);

        return redirect()->route('admin.workflows.edit', $workflow)->with('status', "Version {$version->version} wiederhergestellt.");
    }

    private function isGlobal(Workflow $workflow): bool
    {
        return $workflow->application_id === null && $workflow->phase !== Workflow::PHASE_EVENT;
    }

    /** Nur bekannte Felder übernehmen; Konfiguration als Objekt speichern. */
    private function normalize(array $graph): array
    {
        return [
            'nodes' => collect($graph['nodes'])->map(fn ($node) => [
                'id' => (string) ($node['id'] ?? ''),
                'type' => (string) ($node['type'] ?? ''),
                'config' => (object) (is_array($node['config'] ?? null) ? $node['config'] : []),
                'position' => [
                    'x' => (int) ($node['position']['x'] ?? 0),
                    'y' => (int) ($node['position']['y'] ?? 0),
                ],
            ])->values()->all(),
            'edges' => collect($graph['edges'])->map(fn ($edge) => [
                'from' => (string) ($edge['from'] ?? ''),
                'output' => (string) ($edge['output'] ?? ''),
                'to' => (string) ($edge['to'] ?? ''),
            ])->values()->all(),
        ];
    }
}
