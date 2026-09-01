<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['directory_group_id', 'group_name', 'directory_id', 'role', 'claims'])]
class GroupRoleMapping extends Model
{
    protected function casts(): array
    {
        return [
            'claims' => 'array',
        ];
    }

    public function directoryGroup(): BelongsTo
    {
        return $this->belongsTo(DirectoryGroup::class);
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** Angezeigter Gruppenname, egal ob verknüpft oder frei eingetragen. */
    public function groupLabel(): string
    {
        return $this->directoryGroup?->name ?? (string) $this->group_name;
    }

    /** Angezeigtes Verzeichnis (oder "alle"). */
    public function directoryLabel(): string
    {
        return $this->directoryGroup?->directory?->name
            ?? $this->directory?->name
            ?? 'alle';
    }
}
