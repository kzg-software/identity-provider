<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'directory_id', 'user_id', 'object_guid', 'sid', 'sam_account_name', 'upn',
    'first_name', 'last_name', 'display_name', 'email', 'phone', 'department',
    'company', 'position', 'office', 'manager', 'distinguished_name', 'domain',
    'account_status', 'extra_attributes',
])]
class DirectoryUser extends Model
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            DirectoryGroup::class,
            'directory_group_memberships'
        )->withPivot('is_nested', 'synced_at')->withTimestamps();
    }
}
