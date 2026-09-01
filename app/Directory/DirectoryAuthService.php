<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use App\Models\User;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Throwable;

/**
 * Authenticates an end user against Active Directory with the credentials
 * they typed into the login form (as opposed to DirectoryTestService::testAuthenticate,
 * which is an admin diagnostic tool). On success, syncs the user via
 * DirectorySyncService so the local mirror (users / directory_users) is
 * up to date, exactly like the Windows-SSO and scheduled sync paths do.
 */
class DirectoryAuthService
{
    /**
     * @return array{ok: bool, user?: User, message?: string}
     */
    public function attempt(string $usernameInput, string $password): array
    {
        $username = trim($usernameInput);
        [$domainOrSuffix, $samAccountName] = DirectoryResolver::parseDomainQualifiedUsername($username);

        $directories = DirectoryResolver::candidates($domainOrSuffix);

        if ($directories->isEmpty()) {
            return ['ok' => false, 'message' => 'Anmeldedaten sind ungültig.'];
        }

        foreach ($directories as $directory) {
            /** @var DirectoryModel $directory */
            try {
                $result = $this->attemptAgainstDirectory($directory, $samAccountName, $username, $password);
            } catch (Throwable $e) {
                // Ein nicht erreichbares Verzeichnis darf den Login nie zum Absturz bringen;
                // einfach das nächste Verzeichnis probieren bzw. am Ende generisch fehlschlagen.
                continue;
            }

            if ($result !== null) {
                return $result;
            }
        }

        return ['ok' => false, 'message' => 'Anmeldedaten sind ungültig.'];
    }

    /**
     * @return array{ok: bool, user?: User, message?: string}|null Null means
     *         "this directory has no such user / bind failed for another
     *         reason unrelated to the entered password" — caller should try
     *         the next directory rather than stop.
     */
    private function attemptAgainstDirectory(DirectoryModel $directory, string $samAccountName, string $rawUsername, string $password): ?array
    {
        $connection = DirectoryConnectionResolver::connect($directory);
        $connectionName = DirectoryConnectionResolver::connectionName($directory);

        $ldapUser = LdapUser::on($connectionName)
            ->in($directory->userSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
            ->where('samaccountname', '=', $samAccountName)
            ->orWhere('userprincipalname', '=', $rawUsername)
            ->first();

        if (! $ldapUser) {
            return null;
        }

        $bindIdentifier = $ldapUser->getFirstAttribute('userprincipalname') ?: $ldapUser->getDn();

        // Bind with the END USER's own credentials (never the service bind account)
        // to actually verify the password against the directory.
        $authenticated = $connection->auth()->attempt($bindIdentifier, $password);

        if (! $authenticated) {
            return ['ok' => false, 'message' => 'Anmeldedaten sind ungültig.'];
        }

        $user = (new DirectorySyncService)->syncSingleUser($directory, $connectionName, $ldapUser);

        if (! $user) {
            return ['ok' => false, 'message' => 'Anmeldedaten sind ungültig.'];
        }

        if (! $user->is_active) {
            return ['ok' => false, 'message' => 'Dieses Konto ist gesperrt.'];
        }

        return ['ok' => true, 'user' => $user];
    }

}
