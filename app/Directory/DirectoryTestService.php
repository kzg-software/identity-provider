<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Models\ActiveDirectory\User;
use Throwable;

/**
 * Ad-hoc LDAP diagnostic operations used by the admin UI (Verbindung testen,
 * Benutzer/Gruppe suchen, Testbenutzer authentifizieren, LDAP-Abfrage testen).
 *
 * Every public method catches LDAP failures and returns a structured result
 * instead of throwing, so an unreachable directory never crashes the app.
 */
class DirectoryTestService
{
    public function testConnection(DirectoryModel $directory): array
    {
        try {
            DirectoryConnectionResolver::connect($directory);

            return ['ok' => true, 'message' => 'Verbindung und Bind erfolgreich.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyMessage($e)];
        }
    }

    public function searchUser(DirectoryModel $directory, string $term): array
    {
        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $base = DirectoryConnectionResolver::resolveBaseDn($directory, $name);
            $searchDn = DirectoryModel::cleanDn($directory->user_dn) ?: $base;

            $results = User::on($name)
                ->in($searchDn)
                ->where(function ($query) use ($term) {
                    $query->orWhere('samaccountname', 'contains', $term)
                        ->orWhere('userprincipalname', 'contains', $term)
                        ->orWhere('cn', 'contains', $term)
                        ->orWhere('mail', 'contains', $term);
                })
                ->limit(25)
                ->get();

            return [
                'ok' => true,
                'results' => $results->map(fn (User $user) => [
                    'dn' => $user->getDn(),
                    'sam_account_name' => $user->getFirstAttribute('samaccountname'),
                    'upn' => $user->getFirstAttribute('userprincipalname'),
                    'display_name' => $user->getFirstAttribute('displayname') ?? $user->getFirstAttribute('cn'),
                    'mail' => $user->getFirstAttribute('mail'),
                ])->all(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyMessage($e)];
        }
    }

    public function searchGroup(DirectoryModel $directory, string $term): array
    {
        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $base = DirectoryConnectionResolver::resolveBaseDn($directory, $name);
            $searchDn = DirectoryModel::cleanDn($directory->group_dn) ?: $base;

            $results = Group::on($name)
                ->in($searchDn)
                ->where('cn', 'contains', $term)
                ->limit(25)
                ->get();

            return [
                'ok' => true,
                'results' => $results->map(fn (Group $group) => [
                    'dn' => $group->getDn(),
                    'name' => $group->getFirstAttribute('cn'),
                    'description' => $group->getFirstAttribute('description'),
                ])->all(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyMessage($e)];
        }
    }

    public function testAuthenticate(DirectoryModel $directory, string $username, string $password): array
    {
        try {
            $connection = DirectoryConnectionResolver::connect($directory);

            $ok = $connection->auth()->attempt($this->qualifyUsername($directory, $username), $password);

            return $ok
                ? ['ok' => true, 'message' => "Authentifizierung für '{$username}' erfolgreich."]
                : ['ok' => false, 'message' => "Authentifizierung für '{$username}' fehlgeschlagen (ungültige Zugangsdaten)."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyMessage($e)];
        }
    }

    /**
     * Runs a raw LDAP filter query against the directory for diagnostic purposes.
     * Uses LdapRecord's query builder (never string-concatenates user input into
     * a filter) to avoid LDAP injection.
     */
    public function rawQuery(DirectoryModel $directory, string $filter, int $limit = 25): array
    {
        try {
            $connection = DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $base = DirectoryConnectionResolver::resolveBaseDn($directory, $name);

            if ($base === null) {
                return [
                    'ok' => false,
                    'message' => 'Für dieses Verzeichnis ist keine Base DN hinterlegt und sie ließ sich '
                        .'auch nicht automatisch ermitteln. Bitte unter "Bearbeiten" eintragen, '
                        .'z. B. DC=firma,DC=local.',
                ];
            }

            $results = $connection->query()
                ->setBaseDn($base)
                ->rawFilter(trim($filter))
                ->limit($limit)
                ->get();

            return [
                'ok' => true,
                'results' => collect($results)->map(fn ($entry) => [
                    'dn' => $entry['dn'][0] ?? ($entry['dn'] ?? null),
                    'attributes' => collect($entry)->except(['dn', 'count'])->map(
                        fn ($v) => is_array($v) ? collect($v)->except('count')->implode(', ') : $v
                    )->all(),
                ])->all(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyMessage($e)];
        }
    }

    private function qualifyUsername(DirectoryModel $directory, string $username): string
    {
        if (str_contains($username, '@') || str_contains($username, '\\')) {
            return $username;
        }

        return $directory->upn_suffix ? "{$username}@{$directory->upn_suffix}" : $username;
    }

    private function friendlyMessage(Throwable $e): string
    {
        $raw = $e->getMessage();
        $lower = strtolower($raw);

        if (str_contains($lower, 'invalid dn syntax')) {
            return 'LDAP-Fehler: Ungültige DN-Syntax. Das betrifft nicht den Filter, sondern den '
                .'Suchpfad (Base DN bzw. User/Group DN) des Verzeichnisses. Häufig steckt ein '
                .'unsichtbarer Zeilenumbruch oder ein Leerzeichen vom Kopieren darin. Bitte die '
                .'DN-Felder unter "Bearbeiten" neu eintragen.';
        }

        if (str_contains($lower, 'bad search filter') || str_contains($lower, 'filter error')) {
            return 'LDAP-Fehler: Der Suchfilter ist ungültig. Klammern müssen paarweise geschlossen '
                .'sein, z. B. (&(objectClass=user)(memberOf=CN=Gruppe,OU=...,DC=firma,DC=local)).';
        }

        if ($e instanceof LdapRecordException) {
            return 'LDAP-Fehler: '.$raw;
        }

        return 'Verbindung fehlgeschlagen: '.$raw;
    }
}
