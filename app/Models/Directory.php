<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /*
     * DN-Felder werden beim Speichern bereinigt: Zeilenumbrüche und
     * umgebende Leerzeichen aus einem Copy-and-paste sind die häufigste
     * Ursache für "ldap_search(): Invalid DN syntax", obwohl der Wert auf
     * den ersten Blick richtig aussieht.
     */
    protected function baseDn(): Attribute
    {
        return Attribute::make(set: fn ($value) => static::cleanDn($value));
    }

    protected function userDn(): Attribute
    {
        return Attribute::make(set: fn ($value) => static::cleanDn($value));
    }

    protected function groupDn(): Attribute
    {
        return Attribute::make(set: fn ($value) => static::cleanDn($value));
    }

    protected function bindUser(): Attribute
    {
        return Attribute::make(set: fn ($value) => static::cleanDn($value));
    }

    /**
     * Bereinigter, zum Durchsuchen genutzter Base DN (oder null).
     */
    public function searchBaseDn(): ?string
    {
        return static::cleanDn($this->base_dn);
    }

    public function userSearchDn(): ?string
    {
        return static::cleanDn($this->user_dn) ?: $this->searchBaseDn();
    }

    public function groupSearchDn(): ?string
    {
        return static::cleanDn($this->group_dn) ?: $this->searchBaseDn();
    }

    /**
     * Entfernt Zeilenumbrüche und umgebende Leerzeichen. Leere Werte -> null.
     */
    public static function cleanDn(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\r", "\n", "\t"], '', (string) $value);
        $value = trim($value);

        return $value === '' ? null : $value;
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
