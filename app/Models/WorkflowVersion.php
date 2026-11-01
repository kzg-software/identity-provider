<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workflow_id', 'version', 'graph', 'created_by', 'created_at'])]
class WorkflowVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
