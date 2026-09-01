<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use LdapRecord\Container;
use LdapRecord\Connection;
use LdapRecord\Models\ActiveDirectory\Entry as ActiveDirectoryEntry;
use Throwable;

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

    /**
     * Bereinigter Base DN für Suchen. Ist im Verzeichnis keiner hinterlegt,
     * wird er einmalig aus dem RootDSE der Domäne ermittelt und gespeichert.
     * Gibt null zurück, wenn beides nicht klappt.
     */
    public static function resolveBaseDn(DirectoryModel $directory, ?string $connectionName = null): ?string
    {
        $base = $directory->searchBaseDn();

        if ($base !== null) {
            return $base;
        }

        $connectionName ??= self::connectionName($directory);

        try {
            $rootDse = ActiveDirectoryEntry::getRootDse($connectionName);

            $discovered = DirectoryModel::cleanDn(
                $rootDse->getFirstAttribute('defaultnamingcontext')
                ?? $rootDse->getFirstAttribute('rootdomainnamingcontext')
                ?? $rootDse->getFirstAttribute('namingcontexts')
            );
        } catch (Throwable) {
            $discovered = null;
        }

        if ($discovered !== null && $directory->exists) {
            $directory->forceFill(['base_dn' => $discovered])->saveQuietly();
        }

        return $discovered;
    }
}
