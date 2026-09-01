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

            $results = User::on($name)
                ->in($directory->user_dn ?: $directory->base_dn)
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

            $results = Group::on($name)
                ->in($directory->group_dn ?: $directory->base_dn)
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

            $results = $connection->query()
                ->setBaseDn($directory->base_dn)
                ->rawFilter($filter)
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
        if ($e instanceof LdapRecordException) {
            return 'LDAP-Fehler: '.$e->getMessage();
        }

        return 'Verbindung fehlgeschlagen: '.$e->getMessage();
    }
}
