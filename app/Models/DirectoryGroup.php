<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['directory_id', 'object_guid', 'sid', 'name', 'distinguished_name', 'description', 'extra_attributes'])]
class DirectoryGroup extends Model
{
    protected function casts(): array
    {
        return [
            'extra_attributes' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function directoryUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            DirectoryUser::class,
            'directory_group_memberships'
        )->withPivot('is_nested', 'synced_at')->withTimestamps();
    }

    public function roleMappings(): HasMany
    {
        return $this->hasMany(GroupRoleMapping::class);
    }
}
