<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein vom Admin gestaltbarer Ablauf (Graph aus Bausteinen) für eine Phase:
 * Login, Authorize/SSO oder Ereignis. application_id = null ist der globale
 * Standard, sonst ersetzt der Workflow den globalen für diese Anwendung.
 */
#[Fillable(['phase', 'application_id', 'trigger', 'name', 'description', 'is_active', 'graph', 'version', 'updated_by'])]
class Workflow extends Model
{
    public const PHASE_LOGIN = 'login';

    public const PHASE_AUTHORIZE = 'authorize';

    public const PHASE_EVENT = 'event';

    public const PHASES = [self::PHASE_LOGIN, self::PHASE_AUTHORIZE, self::PHASE_EVENT];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    public static function phaseLabel(string $phase): string
    {
        return match ($phase) {
            self::PHASE_LOGIN => 'Login',
            self::PHASE_AUTHORIZE => 'Authorize / SSO',
            self::PHASE_EVENT => 'Ereignis',
            default => $phase,
        };
    }

    /**
     * Speichert einen neuen Graphen und legt den bisherigen als Version ab.
     */
    public function replaceGraph(array $graph, ?User $by = null): void
    {
        if ($this->exists) {
            WorkflowVersion::create([
                'workflow_id' => $this->id,
                'version' => $this->version,
                'graph' => $this->graph,
                'created_by' => $by?->id,
                'created_at' => now(),
            ]);
        }

        $this->forceFill([
            'graph' => $graph,
            'version' => $this->exists ? $this->version + 1 : 1,
            'updated_by' => $by?->id,
        ])->save();
    }
}
