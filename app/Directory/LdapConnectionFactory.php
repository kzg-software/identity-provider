<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use LdapRecord\Connection;

class LdapConnectionFactory
{
    public static function make(DirectoryModel $directory): Connection
    {
        return new Connection([
            'hosts' => array_filter(array_map('trim', explode(',', (string) $directory->ldap_server))),
            'port' => $directory->ldap_port ?: ($directory->use_ldaps ? 636 : 389),
            'base_dn' => DirectoryModel::cleanDn($directory->base_dn),
            'username' => $directory->bind_user !== null ? trim((string) $directory->bind_user) : null,
            'password' => $directory->bind_password_encrypted,
            'use_tls' => (bool) $directory->use_ldaps,
            'timeout' => 5,
            'follow_referrals' => false,
        ]);
    }
}
