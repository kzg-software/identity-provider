<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'type', 'domain', 'realm', 'netbios_domain', 'domain_controller',
    'ldap_server', 'ldap_port', 'use_ldaps', 'base_dn', 'user_dn', 'group_dn',
    'bind_user', 'bind_password_encrypted', 'upn_suffix', 'kerberos_realm',
    'priority', 'is_active', 'config',
])]
class Directory extends Model
{
    protected function casts(): array
    {
        return [
            'use_ldaps' => 'boolean',
            'is_active' => 'boolean',
            'config' => 'array',
            'last_sync_at' => 'datetime',
            'bind_password_encrypted' => 'encrypted',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function directoryUsers(): HasMany
    {
        return $this->hasMany(DirectoryUser::class);
    }

    public function directoryGroups(): HasMany
    {
        return $this->hasMany(DirectoryGroup::class);
    }
}
