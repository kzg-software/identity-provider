<?php

namespace App\Workflow;

use App\Jobs\RunEventWorkflows;
use App\Models\AuditLog;
use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Welche Audit-Ereignisse Ereignis-Workflows auslösen können, und das
 * Anstoßen dieser Workflows, sobald ein solches Ereignis protokolliert wird.
 */
class EventTriggers
{
    public const EVENTS = [
        'login.success' => 'Anmeldung erfolgreich',
        'login.windows_sso' => 'Windows-Anmeldung erfolgreich',
        'login.failed' => 'Anmeldung fehlgeschlagen',
        'login.2fa_failed' => 'Zwei-Faktor fehlgeschlagen',
        'two_factor.recovery_used' => 'Wiederherstellungscode verwendet',
        'security.new_device' => 'Anmeldung von neuem Gerät',
        'oauth.authorize.success' => 'Zugriff auf OIDC-Anwendung',
        'oauth.authorize.denied' => 'OIDC-Zugriff verweigert (Richtlinie)',
        'saml.sso.success' => 'Zugriff auf SAML-Anwendung',
        'saml.sso.denied' => 'SAML-Zugriff verweigert (Richtlinie)',
        'workflow.denied' => 'Von einem Workflow abgelehnt',
        'workflow.terms_declined' => 'Nutzungsbedingungen abgelehnt',
        'logout' => 'Abmeldung',
    ];

    /** Verhindert, dass Aktionen eines Ereignis-Workflows weitere auslösen. */
    private static bool $running = false;

    public static function label(string $event): string
    {
        return self::EVENTS[$event] ?? $event;
    }

    public static function dispatchFor(AuditLog $log): void
    {
        if (self::$running || ! array_key_exists($log->event, self::EVENTS)) {
            return;
        }

        try {
            if (! Workflow::query()->where('phase', Workflow::PHASE_EVENT)->where('is_active', true)->exists()) {
                return;
            }

            RunEventWorkflows::dispatch($log->id);
        } catch (Throwable $e) {
            // Ereignis-Workflows dürfen nie den eigentlichen Vorgang stören.
            Log::warning('Ereignis-Workflow konnte nicht angestoßen werden', ['event' => $log->event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Führt alle aktiven Ereignis-Workflows aus, deren Auslöser passt.
     */
    public static function run(AuditLog $log, WorkflowRunner $runner): void
    {
        $workflows = Workflow::query()->where('phase', Workflow::PHASE_EVENT)->where('is_active', true)->get();

        self::$running = true;

        try {
            foreach ($workflows as $workflow) {
                $trigger = collect($workflow->graph['nodes'] ?? [])->firstWhere('type', 'trigger');

                if (! $trigger || ! in_array($log->event, (array) ($trigger['config']['events'] ?? []), true)) {
                    continue;
                }

                // Ereignisse ohne Anwendung erreichen nur globale Workflows.
                if ($workflow->application_id !== null && $workflow->application_id !== $log->application_id) {
                    continue;
                }

                $runner->start($workflow, new WorkflowContext('event', $log->user_id, $log->application_id, (string) ($log->metadata['method'] ?? ''), null, [], [
                    'event' => $log->event,
                    'metadata' => $log->metadata ?? [],
                    'ip' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'occurred_at' => $log->created_at?->toIso8601String(),
                ]));
            }
        } finally {
            self::$running = false;
        }
    }
}
