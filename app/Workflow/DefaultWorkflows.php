<?php

namespace App\Workflow;

/**
 * Die mitgelieferten Standard-Workflows. Sie bilden genau das Verhalten ab,
 * das vor der Einführung der Workflows fest im Code stand.
 */
class DefaultWorkflows
{
    public static function name(string $phase): string
    {
        return match ($phase) {
            'login' => 'Standard-Login',
            'authorize' => 'Standard-Zugriff auf Anwendungen',
            default => 'Workflow',
        };
    }

    /** @return array{nodes: list<array>, edges: list<array>} */
    public static function graph(string $phase): array
    {
        return match ($phase) {
            'login' => self::login(),
            'authorize' => self::authorize(),
            default => ['nodes' => [], 'edges' => []],
        };
    }

    /**
     * Start → Zwei-Faktor (falls eingerichtet; vertraute Geräte und
     * passwortlose Passkeys ausgenommen) → Erlauben.
     */
    private static function login(): array
    {
        return [
            'nodes' => [
                self::node('start', 'start', 40, 160),
                self::node('mfa', 'two_factor', 300, 140, ['skip_trusted' => true, 'passkey_counts' => true, 'windows_counts' => true]),
                self::node('allow', 'allow', 600, 160),
            ],
            'edges' => [
                self::edge('start', 'weiter', 'mfa'),
                self::edge('mfa', 'bestaetigt', 'allow'),
                self::edge('mfa', 'nicht_eingerichtet', 'allow'),
            ],
        ];
    }

    /**
     * Start → Wartung → Netzwerk/Uhrzeit → Richtlinien → Einwilligung →
     * Erlauben; jede blockierende Prüfung endet in „Ablehnen“ mit der
     * Meldung der Prüfung.
     */
    private static function authorize(): array
    {
        return [
            'nodes' => [
                self::node('start', 'start', 40, 120),
                self::node('maintenance', 'check.maintenance', 240, 100),
                self::node('restrictions', 'check.restrictions', 480, 100),
                self::node('policies', 'check.policies', 720, 100),
                self::node('consent', 'consent', 960, 120),
                self::node('allow', 'allow', 1200, 120),
                self::node('deny', 'deny', 720, 340, ['message' => '']),
            ],
            'edges' => [
                self::edge('start', 'weiter', 'maintenance'),
                self::edge('maintenance', 'ok', 'restrictions'),
                self::edge('maintenance', 'blockiert', 'deny'),
                self::edge('restrictions', 'ok', 'policies'),
                self::edge('restrictions', 'blockiert', 'deny'),
                self::edge('policies', 'ok', 'consent'),
                self::edge('policies', 'blockiert', 'deny'),
                self::edge('consent', 'weiter', 'allow'),
            ],
        ];
    }

    private static function node(string $id, string $type, int $x, int $y, array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'config' => (object) $config, 'position' => ['x' => $x, 'y' => $y]];
    }

    private static function edge(string $from, string $output, string $to): array
    {
        return ['from' => $from, 'output' => $output, 'to' => $to];
    }
}
