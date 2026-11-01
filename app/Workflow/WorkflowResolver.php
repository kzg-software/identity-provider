<?php

namespace App\Workflow;

use App\Models\Application;
use App\Models\Workflow;

/**
 * Welcher Workflow gilt? Ein aktiver Workflow der Anwendung ersetzt den
 * globalen. Fehlt der globale (frische Installation), wird er mit dem
 * Standard angelegt.
 */
class WorkflowResolver
{
    public static function for(string $phase, ?Application $application = null): Workflow
    {
        if ($application) {
            $override = Workflow::query()
                ->where('phase', $phase)
                ->where('application_id', $application->getKey())
                ->where('is_active', true)
                ->first();

            if ($override) {
                return $override;
            }
        }

        return self::global($phase);
    }

    public static function global(string $phase): Workflow
    {
        $workflow = Workflow::query()
            ->where('phase', $phase)
            ->whereNull('application_id')
            ->orderBy('id')
            ->first();

        return $workflow ?? Workflow::create([
            'phase' => $phase,
            'application_id' => null,
            'name' => DefaultWorkflows::name($phase),
            'is_active' => true,
            'graph' => DefaultWorkflows::graph($phase),
            'version' => 1,
        ]);
    }
}
