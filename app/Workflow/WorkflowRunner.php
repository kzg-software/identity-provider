<?php

namespace App\Workflow;

use App\Models\AuditLog;
use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt einen Workflow-Graphen aus. Ergebnis ist immer Erlauben, Ablehnen
 * oder eine Interaktion; bei einer Interaktion wird der Lauf in der Session
 * geparkt und später mit resume() an derselben Stelle fortgesetzt.
 *
 * Fehler (kaputter Graph, Ausnahme in einem Baustein) führen zu Ablehnen –
 * ein Workflow lässt im Zweifel niemanden durch.
 */
class WorkflowRunner
{
    private const MAX_STEPS = 100;

    private const RUN_TTL_SECONDS = 900;

    public function __construct(private NodeRegistry $registry) {}

    public function start(Workflow $workflow, WorkflowContext $context): NodeResult
    {
        $this->forget($context->phase);

        $entry = collect($workflow->graph['nodes'] ?? [])
            ->first(fn ($node) => $this->registry->get($node['type'] ?? '')?->isEntry());

        if (! $entry) {
            return $this->fail($workflow, $context, 'Der Workflow hat keinen Start-Baustein.');
        }

        return $this->run($workflow, $context, $entry['id']);
    }

    /**
     * Setzt einen geparkten Lauf fort. $mutate passt den Kontext vorher an
     * (z. B. Merker mfa_done). Gibt null zurück, wenn kein gültiger Lauf
     * wartet oder der Workflow inzwischen geändert wurde.
     */
    public function resume(string $phase, ?callable $mutate = null): ?NodeResult
    {
        $state = $this->pending($phase);

        if (! $state) {
            return null;
        }

        $workflow = Workflow::query()->find($state['workflow_id']);

        if (! $workflow || $workflow->version !== $state['version']) {
            $this->forget($phase);

            return null;
        }

        $context = WorkflowContext::fromArray($state['context']);

        if ($mutate) {
            $mutate($context);
        }

        return $this->run($workflow, $context, $state['node']);
    }

    /** @return array{workflow_id: int, version: int, node: string, context: array, expires_at: int}|null */
    public function pending(string $phase): ?array
    {
        $state = session()->get($this->key($phase));

        if (! is_array($state) || ($state['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $state;
    }

    public function pendingContext(string $phase): ?WorkflowContext
    {
        $state = $this->pending($phase);

        if (! $state) {
            return null;
        }

        $context = WorkflowContext::fromArray($state['context']);
        $context->currentNode = $state['node'];

        return $context;
    }

    /**
     * Der Baustein, an dem der geparkte Lauf wartet, samt Konfiguration.
     *
     * @return array{type: NodeType, config: array}|null
     */
    public function pendingNode(string $phase): ?array
    {
        $state = $this->pending($phase);
        $workflow = $state ? Workflow::query()->find($state['workflow_id']) : null;

        if (! $workflow || $workflow->version !== $state['version']) {
            return null;
        }

        $node = collect($workflow->graph['nodes'] ?? [])->firstWhere('id', $state['node']);
        $type = $node ? $this->registry->get($node['type'] ?? '') : null;

        return $type ? ['type' => $type, 'config' => (array) ($node['config'] ?? [])] : null;
    }

    /** Ändert den Kontext eines geparkten Laufs, ohne ihn fortzusetzen. */
    public function updatePending(string $phase, callable $mutate): void
    {
        $state = $this->pending($phase);

        if (! $state) {
            return;
        }

        $context = WorkflowContext::fromArray($state['context']);
        $mutate($context);
        $state['context'] = $context->toArray();

        session()->put($this->key($phase), $state);
    }

    public function forget(string $phase): void
    {
        // Ereignis-Workflows laufen ohne Sitzung (Queue) und parken nie.
        if ($phase !== 'event') {
            session()->forget($this->key($phase));
        }
    }

    private function run(Workflow $workflow, WorkflowContext $context, string $nodeId): NodeResult
    {
        $nodes = collect($workflow->graph['nodes'] ?? [])->keyBy('id');
        $edges = $workflow->graph['edges'] ?? [];
        $path = [];

        try {
            for ($step = 0; $step < self::MAX_STEPS; $step++) {
                $node = $nodes->get($nodeId);
                $type = $node ? $this->registry->get($node['type'] ?? '') : null;

                if (! $type) {
                    return $this->fail($workflow, $context, "Unbekannter Baustein „{$nodeId}“.", $path);
                }

                $path[] = $nodeId;
                $context->currentNode = $nodeId;
                $result = $type->run((array) ($node['config'] ?? []), $context);

                if ($result->kind === NodeResult::NEXT) {
                    $edge = collect($edges)->first(fn ($e) => $e['from'] === $nodeId && $e['output'] === $result->output);

                    if (! $edge) {
                        return $this->fail($workflow, $context, "Ausgang „{$result->output}“ von „{$type->label()}“ ist nicht verbunden.", $path);
                    }

                    $nodeId = $edge['to'];

                    continue;
                }

                $result->path = $path;
                $result->context = $context;

                if ($result->isInteract()) {
                    session()->put($this->key($context->phase), [
                        'workflow_id' => $workflow->id,
                        'version' => $workflow->version,
                        'node' => $nodeId,
                        'context' => $context->toArray(),
                        'expires_at' => now()->getTimestamp() + self::RUN_TTL_SECONDS,
                    ]);

                    return $result;
                }

                $this->forget($context->phase);

                if ($result->isDeny()) {
                    AuditLog::record('workflow.denied', $context->user(), [
                        'workflow' => $workflow->name,
                        'phase' => $context->phase,
                        'path' => $path,
                    ], $context->application());
                }

                return $result;
            }

            return $this->fail($workflow, $context, 'Der Workflow hat zu viele Schritte (Schleife?).', $path);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($workflow, $context, $e->getMessage(), $path);
        }
    }

    /**
     * Testlauf für den Editor: läuft ohne Seiteneffekte durch den Graphen.
     * Interaktionen gelten als vom Nutzer erfolgreich abgeschlossen (erster
     * Ausgang des Bausteins).
     *
     * @return array{outcome: string, message: string|null, steps: list<array{node: string, label: string, output: string|null, note: string|null}>}
     */
    public function simulate(Workflow $workflow, WorkflowContext $context): array
    {
        $context->dryRun = true;
        $nodes = collect($workflow->graph['nodes'] ?? [])->keyBy('id');
        $edges = collect($workflow->graph['edges'] ?? []);
        $steps = [];

        $nodeId = $nodes->first(fn ($node) => $this->registry->get($node['type'] ?? '')?->isEntry())['id'] ?? null;

        for ($i = 0; $nodeId !== null && $i < self::MAX_STEPS; $i++) {
            $node = $nodes->get($nodeId);
            $type = $node ? $this->registry->get($node['type'] ?? '') : null;

            if (! $type) {
                return ['outcome' => 'error', 'message' => "Unbekannter Baustein „{$nodeId}“.", 'steps' => $steps];
            }

            $context->currentNode = $nodeId;
            $config = (array) ($node['config'] ?? []);

            try {
                $result = $type->run($config, $context);
            } catch (Throwable $e) {
                return ['outcome' => 'error', 'message' => $e->getMessage(), 'steps' => $steps];
            }

            $note = null;
            $output = $result->output;

            if ($result->isInteract()) {
                $output = array_key_first($type->outputs($config));
                $note = match ($result->interaction) {
                    'two_factor' => 'Zwei-Faktor-Abfrage (im Test als bestanden angenommen)',
                    'oidc_consent' => 'Einwilligungsseite (im Test als erteilt angenommen)',
                    default => 'Seite wird angezeigt (im Test als bestätigt angenommen)',
                };
            }

            $steps[] = ['node' => $nodeId, 'label' => $type->label(), 'output' => $output, 'note' => $note];

            if ($result->isAllow() || $result->isDeny()) {
                return [
                    'outcome' => $result->isAllow() ? 'allow' : 'deny',
                    'message' => $result->message,
                    'steps' => $steps,
                ];
            }

            $nodeId = $edges->first(fn ($e) => $e['from'] === $nodeId && $e['output'] === $output)['to'] ?? null;

            if ($nodeId === null) {
                return ['outcome' => 'error', 'message' => "Ausgang „{$output}“ von „{$type->label()}“ ist nicht verbunden.", 'steps' => $steps];
            }
        }

        return ['outcome' => 'error', 'message' => 'Kein Start-Baustein oder zu viele Schritte.', 'steps' => $steps];
    }

    private function fail(Workflow $workflow, WorkflowContext $context, string $reason, array $path = []): NodeResult
    {
        $this->forget($context->phase);

        Log::error('Workflow abgebrochen', ['workflow' => $workflow->id, 'reason' => $reason, 'path' => $path]);
        AuditLog::record('workflow.error', $context->user(), [
            'workflow' => $workflow->name,
            'phase' => $context->phase,
            'reason' => $reason,
            'path' => $path,
        ], $context->application());

        $result = NodeResult::deny('Der Vorgang konnte wegen eines Konfigurationsfehlers nicht abgeschlossen werden. Bitte wende dich an den Administrator.', 500);
        $result->path = $path;
        $result->context = $context;

        return $result;
    }

    private function key(string $phase): string
    {
        return 'workflow.run.'.$phase;
    }
}
