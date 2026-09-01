<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use LdapRecord\Container;
use LdapRecord\Connection;

/**
 * Registers a Directory model's LDAP connection with LdapRecord's connection
 * container so that ActiveDirectory model classes (User, Group, ...) can be
 * bound to it via ::on($name).
 */
class DirectoryConnectionResolver
{
    public static function connectionName(DirectoryModel $directory): string
    {
        return 'directory_'.$directory->id;
    }

    public static function connect(DirectoryModel $directory): Connection
    {
        $name = self::connectionName($directory);

        // Reuse an already-registered connection for this directory (e.g. one
        // swapped in by DirectoryEmulator in tests) instead of overwriting it.
        if (Container::getInstance()->getConnectionManager()->hasConnection($name)) {
            return Container::getConnection($name);
        }

        $connection = LdapConnectionFactory::make($directory);

        Container::addConnection($connection, $name);

        // Throws \LdapRecord\Auth\BindException / \LdapRecord\LdapRecordException on failure.
        $connection->connect();

        return $connection;
    }
}
