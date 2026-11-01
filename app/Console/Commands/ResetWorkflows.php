<?php

namespace App\Console\Commands;

use App\Models\Workflow;
use App\Workflow\DefaultWorkflows;
use App\Workflow\WorkflowResolver;
use Illuminate\Console\Command;

/**
 * Notausgang, falls ein Workflow alle aussperrt: setzt die globalen
 * Login-/Zugriffs-Workflows auf den Standard zurück und deaktiviert die
 * Abläufe der Anwendungen.
 */
class ResetWorkflows extends Command
{
    protected $signature = 'workflow:reset
        {--phase= : Nur diese Phase (login oder authorize)}
        {--app= : Nur den eigenen Ablauf dieser Anwendung (ID) deaktivieren}';

    protected $description = 'Setzt die Workflows auf den mitgelieferten Standard zurück (Notfall bei Aussperrung)';

    public function handle(): int
    {
        $phases = $this->option('phase') ? [$this->option('phase')] : [Workflow::PHASE_LOGIN, Workflow::PHASE_AUTHORIZE];

        foreach ($phases as $phase) {
            if (! in_array($phase, [Workflow::PHASE_LOGIN, Workflow::PHASE_AUTHORIZE], true)) {
                $this->error("Unbekannte Phase „{$phase}“ (erlaubt: login, authorize).");

                return self::FAILURE;
            }
        }

        if ($app = $this->option('app')) {
            $count = Workflow::query()->where('application_id', $app)->whereIn('phase', $phases)->update(['is_active' => false]);
            $this->info("{$count} Ablauf/Abläufe der Anwendung {$app} deaktiviert.");

            return self::SUCCESS;
        }

        foreach ($phases as $phase) {
            WorkflowResolver::global($phase)->replaceGraph(DefaultWorkflows::graph($phase));
            $disabled = Workflow::query()->where('phase', $phase)->whereNotNull('application_id')->update(['is_active' => false]);

            $this->info(Workflow::phaseLabel($phase).": Standard wiederhergestellt, {$disabled} Anwendungs-Ablauf/Abläufe deaktiviert.");
        }

        return self::SUCCESS;
    }
}
