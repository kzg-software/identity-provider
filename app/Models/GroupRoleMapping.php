<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['directory_group_id', 'role', 'claims'])]
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
}
