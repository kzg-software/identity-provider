<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use LdapRecord\Models\ActiveDirectory\Group as LdapGroup;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use LdapRecord\Query\Model\Builder;

/**
 * Beschränkt Synchronisierung und Anmeldung auf Mitglieder bestimmter
 * Gruppen (Directory.login_group_filter). Einträge dürfen ein voller
 * Gruppen-DN oder ein CN sein; CNs werden im Gruppen-Suchbereich aufgelöst.
 * Die Prüfung erfolgt verschachtelt (LDAP_MATCHING_RULE_IN_CHAIN).
 */
class GroupMembershipFilter
{
    /** @var array<string, array<int, string>> */
    private static array $dnCache = [];

    /**
     * Volle Gruppen-DNs für die Filtergruppen eines Verzeichnisses.
     *
     * @return array<int, string>
     */
    public static function groupDns(DirectoryModel $directory, string $connectionName): array
    {
        $entries = $directory->loginGroupFilters();

        if ($entries === []) {
            return [];
        }

        $cacheKey = $connectionName.'|'.implode('|', $entries);

        if (isset(self::$dnCache[$cacheKey])) {
            return self::$dnCache[$cacheKey];
        }

        $searchBase = $directory->groupSearchDn()
            ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName);

        $dns = [];

        foreach ($entries as $entry) {
            if (self::looksLikeDn($entry)) {
                $dns[] = $entry;

                continue;
            }

            $group = LdapGroup::on($connectionName)
                ->in($searchBase)
                ->where('cn', '=', $entry)
                ->first();

            if ($group) {
                $dns[] = $group->getDn();
            }
        }

        return self::$dnCache[$cacheKey] = array_values(array_unique(array_filter($dns)));
    }

    /**
     * Hängt "(|(memberof:1.2.840.113556.1.4.1941:=DN)...)" an eine
     * Benutzer-Abfrage an. Ohne Filtergruppen bleibt die Abfrage unverändert.
     *
     * @param  array<int, string>  $groupDns
     */
    public static function constrain(Builder $query, array $groupDns): Builder
    {
        if ($groupDns === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($groupDns) {
            foreach ($groupDns as $dn) {
                $q->orWhereMemberof($dn, true);
            }
        });
    }

    /**
     * Ist der Benutzer (verschachtelt) Mitglied mindestens einer Filtergruppe?
     * Ohne Filtergruppen immer true.
     */
    public static function allows(DirectoryModel $directory, string $connectionName, LdapUser $ldapUser): bool
    {
        $dns = self::groupDns($directory, $connectionName);

        if ($dns === []) {
            return true;
        }

        $dn = $ldapUser->getDn();

        if (! $dn) {
            return false;
        }

        // Base-Scope-Suche direkt auf das Benutzerobjekt plus memberof-Filter:
        // trifft die Abfrage, ist der Benutzer Mitglied.
        $query = LdapUser::on($connectionName)->in($dn)->read();

        return self::constrain($query, $dns)->exists();
    }

    private static function looksLikeDn(string $value): bool
    {
        return (bool) preg_match('/^(cn|ou|dc)=.+,.*(dc|ou)=/i', trim($value));
    }
}
