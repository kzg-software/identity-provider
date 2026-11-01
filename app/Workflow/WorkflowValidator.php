<?php

namespace App\Workflow;

/**
 * Prüft einen Graphen vor dem Speichern. Ein gespeicherter Workflow ist
 * damit immer vollständig: genau ein Start, jeder Ausgang verbunden, keine
 * Schleifen, jeder Baustein erreichbar und richtig konfiguriert.
 */
class WorkflowValidator
{
    public function __construct(private NodeRegistry $registry) {}

    /**
     * @return array{general: list<string>, nodes: array<string, list<string>>}
     */
    public function validate(string $phase, array $graph): array
    {
        $general = [];
        $nodeErrors = [];
        $nodes = [];

        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            $id = (string) ($node['id'] ?? '');
            $type = $this->registry->get((string) ($node['type'] ?? ''));

            if ($id === '' || isset($nodes[$id])) {
                $general[] = 'Ein Baustein hat keine oder eine doppelte Kennung.';

                continue;
            }

            $nodes[$id] = ['node' => $node, 'type' => $type];

            if (! $type) {
                $nodeErrors[$id][] = 'Unbekannter Bausteintyp.';

                continue;
            }

            if (! in_array($phase, $type->phases(), true)) {
                $nodeErrors[$id][] = "„{$type->label()}“ ist in diesem Workflow nicht verfügbar.";
            }

            foreach ($type->validate($type->withDefaults((array) ($node['config'] ?? []))) as $message) {
                $nodeErrors[$id][] = $message;
            }
        }

        $entries = array_keys(array_filter($nodes, fn ($n) => $n['type']?->isEntry()));

        if (count($entries) !== 1) {
            $general[] = $phase === 'event'
                ? 'Der Workflow braucht genau einen „Auslöser“.'
                : 'Der Workflow braucht genau einen „Start“.';
        }

        // Kanten prüfen und je Knoten/Ausgang zuordnen.
        $targets = [];

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $output = (string) ($edge['output'] ?? '');

            if (! isset($nodes[$from], $nodes[$to])) {
                $general[] = 'Eine Verbindung zeigt auf einen nicht vorhandenen Baustein.';

                continue;
            }

            $fromType = $nodes[$from]['type'];

            if ($fromType && ! array_key_exists($output, $fromType->outputs([]))) {
                $nodeErrors[$from][] = "Unbekannter Ausgang „{$output}“.";

                continue;
            }

            if ($nodes[$to]['type']?->isEntry()) {
                $nodeErrors[$to][] = 'Der Start-Baustein darf keinen Eingang haben.';
            }

            if (isset($targets[$from][$output])) {
                $nodeErrors[$from][] = "Ausgang „{$output}“ ist mehrfach verbunden.";
            }

            $targets[$from][$output] = $to;
        }

        foreach ($nodes as $id => $entry) {
            $type = $entry['type'];

            if (! $type) {
                continue;
            }

            foreach ($type->outputs([]) as $output => $label) {
                if (! isset($targets[$id][$output])) {
                    $nodeErrors[$id][] = "Ausgang „{$label}“ ist nicht verbunden.";
                }
            }
        }

        if (count($entries) === 1) {
            $reachable = $this->reachable($entries[0], $targets);

            foreach (array_keys($nodes) as $id) {
                if (! isset($reachable[$id])) {
                    $nodeErrors[$id][] = 'Dieser Baustein ist vom Start aus nicht erreichbar.';
                }
            }

            if ($this->hasCycle($entries[0], $targets)) {
                $general[] = 'Der Workflow enthält eine Schleife. Verbindungen dürfen nicht zurück zu einem früheren Baustein führen.';
            }

            if ($phase !== 'event' && ! collect(array_keys($reachable))->contains(fn ($id) => ($nodes[$id]['type']?->key()) === 'allow')) {
                $general[] = 'Mindestens ein Weg muss bei „Erlauben“ enden, sonst könnte sich niemand mehr anmelden.';
            }
        }

        return [
            'general' => array_values(array_unique($general)),
            'nodes' => array_map(fn ($list) => array_values(array_unique($list)), $nodeErrors),
        ];
    }

    public static function passes(array $result): bool
    {
        return $result['general'] === [] && $result['nodes'] === [];
    }

    /** @return array<string, true> */
    private function reachable(string $start, array $targets): array
    {
        $seen = [];
        $stack = [$start];

        while ($stack) {
            $id = array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($targets[$id] ?? [] as $to) {
                $stack[] = $to;
            }
        }

        return $seen;
    }

    private function hasCycle(string $start, array $targets): bool
    {
        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $targets): bool {
            if (($state[$id] ?? 0) === 1) {
                return true;
            }

            if (($state[$id] ?? 0) === 2) {
                return false;
            }

            $state[$id] = 1;

            foreach ($targets[$id] ?? [] as $to) {
                if ($visit($to)) {
                    return true;
                }
            }

            $state[$id] = 2;

            return false;
        };

        return $visit($start);
    }
}
