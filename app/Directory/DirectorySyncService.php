<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\GroupRoleMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\Entry as ActiveDirectoryEntry;
use LdapRecord\Models\ActiveDirectory\Group as LdapGroup;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use LdapRecord\Models\Model;
use Throwable;

/**
 * Synchronises users and groups from an Active Directory / LDAP directory
 * into directory_users / directory_groups / directory_group_memberships and
 * mirrors the account into the local users table (auth_source=active_directory).
 */
class DirectorySyncService
{
    /**
     * Hoechster uSNChanged-Wert, der in diesem Lauf ueber Benutzer und Gruppen
     * gesehen wurde. Dient als Cursor fuer die naechste Delta-Synchronisierung.
     */
    private int $maxUsnSeen = 0;

    /**
     * Fuehrt die passende Synchronisierung aus: inkrementell, wenn fuer das
     * Verzeichnis aktiviert und moeglich, sonst voll. Erzwingt $forceFull die
     * volle Synchronisierung (fuer den taeglichen Scheduler-Lauf, der auch
     * verwaiste Konten aufraeumt).
     */
    public function syncNow(DirectoryModel $directory, bool $forceFull = false): array
    {
        return (! $forceFull && $directory->deltaSyncEnabled())
            ? $this->syncDelta($directory)
            : $this->syncAll($directory);
    }

    public function syncAll(DirectoryModel $directory): array
    {
        $start = microtime(true);
        $this->maxUsnSeen = 0;

        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $groupCount = $this->syncGroups($directory, $name);
            $seenGuids = $this->syncUsers($directory, $name);
            $userCount = count($seenGuids);
            $removed = $this->pruneStaleUsers($directory, $seenGuids);

            $duration = (int) round(microtime(true) - $start);

            $directory->forceFill([
                'last_sync_at' => now(),
                'last_full_sync_at' => now(),
                'last_sync_duration_seconds' => $duration,
                'last_sync_user_count' => $userCount,
                'last_sync_removed_count' => $removed,
                'last_sync_group_count' => $groupCount,
                'last_sync_usn' => $this->maxUsnSeen ?: $directory->last_sync_usn,
                'last_sync_directory_host' => $this->directoryHost($name) ?? $directory->last_sync_directory_host,
                'last_sync_error' => null,
            ])->save();

            return [
                'ok' => true,
                'mode' => 'full',
                'users' => $userCount,
                'groups' => $groupCount,
                'removed' => $removed,
                'duration' => $duration,
            ];
        } catch (Throwable $e) {
            Log::warning('Directory-Synchronisierung fehlgeschlagen', [
                'directory_id' => $directory->id,
                'error' => $e->getMessage(),
            ]);

            $directory->forceFill([
                'last_sync_at' => now(),
                'last_sync_duration_seconds' => (int) round(microtime(true) - $start),
                'last_sync_error' => $e->getMessage(),
            ])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Nur Gruppen synchronisieren (z.B. für den 15-Minuten-Scheduler-Job). */
    public function syncGroupsOnly(DirectoryModel $directory): array
    {
        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);
            $count = $this->syncGroups($directory, $name);

            $directory->forceFill(['last_sync_group_count' => $count])->save();

            return ['ok' => true, 'groups' => $count];
        } catch (Throwable $e) {
            Log::warning('Gruppen-Synchronisierung fehlgeschlagen', [
                'directory_id' => $directory->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Inkrementelle Synchronisierung: fragt nur Objekte ab, deren uSNChanged
     * seit dem letzten Lauf gestiegen ist (Active-Directory-Replikations-
     * metadaten). Bei grossen Verzeichnissen um ein Vielfaches schneller als
     * die volle Synchronisierung.
     *
     * Nicht abgedeckt: geloeschte Konten. Eine Delta-Abfrage liefert keine
     * geloeschten Objekte, daher raeumt weiterhin nur die volle
     * Synchronisierung verwaiste Benutzer auf (stale_user_handling). Sie
     * laeuft zusaetzlich taeglich.
     *
     * Faellt automatisch auf die volle Synchronisierung zurueck, wenn:
     * - das Verzeichnis kein Active Directory ist,
     * - noch kein Cursor gespeichert ist (erster Lauf),
     * - der Domaenencontroller gewechselt hat (uSNChanged ist DC-lokal).
     */
    public function syncDelta(DirectoryModel $directory): array
    {
        if ($directory->type !== 'active_directory' || $directory->last_sync_usn === null) {
            return $this->syncAll($directory);
        }

        $start = microtime(true);
        $this->maxUsnSeen = (int) $directory->last_sync_usn;

        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $host = $this->directoryHost($name);

            if ($host !== null && $directory->last_sync_directory_host !== null
                && $host !== $directory->last_sync_directory_host) {
                Log::info('Delta-Synchronisierung: Domaenencontroller gewechselt, volle Synchronisierung', [
                    'directory_id' => $directory->id,
                    'from' => $directory->last_sync_directory_host,
                    'to' => $host,
                ]);

                return $this->syncAll($directory);
            }

            // >= statt >: ein Objekt, das exakt auf dem Cursor geaendert wurde,
            // lieber ein zweites Mal verarbeiten (updateOrCreate ist idempotent)
            // als es zu verpassen.
            $since = max(0, (int) $directory->last_sync_usn);

            $userCount = $this->syncChangedUsers($directory, $name, $since);
            [$groupCount, $touchedUsers] = $this->syncChangedGroups($directory, $name, $since);

            $duration = (int) round(microtime(true) - $start);

            $directory->forceFill([
                'last_sync_at' => now(),
                'last_sync_duration_seconds' => $duration,
                'last_sync_user_count' => $userCount + $touchedUsers,
                'last_sync_removed_count' => 0,
                'last_sync_group_count' => $groupCount,
                'last_sync_usn' => $this->maxUsnSeen,
                'last_sync_directory_host' => $host ?? $directory->last_sync_directory_host,
                'last_sync_error' => null,
            ])->save();

            return [
                'ok' => true,
                'mode' => 'delta',
                'users' => $userCount + $touchedUsers,
                'groups' => $groupCount,
                'removed' => 0,
                'duration' => $duration,
            ];
        } catch (Throwable $e) {
            Log::warning('Delta-Synchronisierung fehlgeschlagen', [
                'directory_id' => $directory->id,
                'error' => $e->getMessage(),
            ]);

            $directory->forceFill([
                'last_sync_at' => now(),
                'last_sync_duration_seconds' => (int) round(microtime(true) - $start),
                'last_sync_error' => $e->getMessage(),
            ])->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Benutzer mit uSNChanged >= $since neu einlesen.
     */
    private function syncChangedUsers(DirectoryModel $directory, string $connectionName, int $since): int
    {
        $query = LdapUser::on($connectionName)
            ->in($directory->userSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
            ->where('usnchanged', '>=', (string) $since);

        $query = GroupMembershipFilter::constrain(
            $query,
            GroupMembershipFilter::groupDns($directory, $connectionName)
        );

        $count = 0;

        foreach ($query->paginate(500) as $ldapUser) {
            /** @var LdapUser $ldapUser */
            if ($this->syncSingleUser($directory, $connectionName, $ldapUser)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Gruppen mit uSNChanged >= $since neu einlesen und die Mitgliedschaften
     * der betroffenen Benutzer neu aufloesen (eine Mitgliedschaftsaenderung
     * erhoeht nur den uSNChanged der Gruppe, nicht den des Benutzers).
     *
     * @return array{0: int, 1: int} [Gruppen, zusaetzlich beruehrte Benutzer]
     */
    private function syncChangedGroups(DirectoryModel $directory, string $connectionName, int $since): array
    {
        $groups = LdapGroup::on($connectionName)
            ->in($directory->groupSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
            ->where('usnchanged', '>=', (string) $since)
            ->paginate(500);

        $count = 0;
        $changedGuids = [];
        $memberDns = [];

        foreach ($groups as $group) {
            /** @var LdapGroup $group */
            $guid = $group->getConvertedGuid();
            if (! $guid) {
                continue;
            }

            DirectoryGroup::updateOrCreate(
                ['directory_id' => $directory->id, 'object_guid' => $guid],
                [
                    'sid' => $group->getConvertedSid(),
                    'name' => $group->getFirstAttribute('cn'),
                    'distinguished_name' => $group->getDn(),
                    'description' => $group->getFirstAttribute('description'),
                    'extra_attributes' => $this->extraAttributes($group),
                    'last_synced_at' => now(),
                ]
            );

            $this->trackUsn($group);
            $count++;
            $changedGuids[] = $guid;

            foreach ((array) $group->getAttribute('member') as $dn) {
                $memberDns[$dn] = true;
            }
        }

        if ($changedGuids === []) {
            return [0, 0];
        }

        // Auch lokal bekannte Mitglieder der geaenderten Gruppen mitnehmen,
        // damit Austritte auffallen.
        $localMemberDns = DirectoryUser::query()
            ->where('directory_id', $directory->id)
            ->whereHas('groups', fn ($q) => $q->whereIn('object_guid', $changedGuids))
            ->pluck('distinguished_name')
            ->filter();

        foreach ($localMemberDns as $dn) {
            $memberDns[$dn] = true;
        }

        $touched = 0;

        foreach (array_keys($memberDns) as $dn) {
            $ldapUser = LdapUser::on($connectionName)->find($dn);

            if ($ldapUser instanceof LdapUser && $this->syncSingleUser($directory, $connectionName, $ldapUser)) {
                $touched++;
            }
        }

        return [$count, $touched];
    }

    /**
     * Hostname des verbundenen Domaenencontrollers aus dem RootDSE, klein
     * geschrieben, oder null. uSNChanged-Werte gelten nur je DC, daher wird
     * der Host als Teil des Delta-Cursors gespeichert.
     */
    private function directoryHost(string $connectionName): ?string
    {
        try {
            $rootDse = ActiveDirectoryEntry::getRootDse($connectionName);

            $host = $rootDse->getFirstAttribute('dnshostname')
                ?? $rootDse->getFirstAttribute('servername');

            return $host ? mb_strtolower(trim((string) $host)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function trackUsn(Model $model): void
    {
        $usn = (int) $model->getFirstAttribute('usnchanged');

        if ($usn > $this->maxUsnSeen) {
            $this->maxUsnSeen = $usn;
        }
    }

    private function syncGroups(DirectoryModel $directory, string $connectionName): int
    {
        // paginate() holt alle Seiten (AD begrenzt get() sonst auf MaxPageSize,
        // meist 1000) - so landen wirklich alle Gruppen im Vorschlags-Katalog.
        $groups = LdapGroup::on($connectionName)
            ->in($directory->groupSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
            ->paginate(500);

        $count = 0;

        foreach ($groups as $group) {
            /** @var LdapGroup $group */
            $guid = $group->getConvertedGuid();
            if (! $guid) {
                continue;
            }

            DirectoryGroup::updateOrCreate(
                ['directory_id' => $directory->id, 'object_guid' => $guid],
                [
                    'sid' => $group->getConvertedSid(),
                    'name' => $group->getFirstAttribute('cn'),
                    'distinguished_name' => $group->getDn(),
                    'description' => $group->getFirstAttribute('description'),
                    'extra_attributes' => $this->extraAttributes($group),
                    'last_synced_at' => now(),
                ]
            );

            $this->trackUsn($group);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, string> object_guids der tatsächlich synchronisierten Benutzer
     */
    private function syncUsers(DirectoryModel $directory, string $connectionName): array
    {
        $query = LdapUser::on($connectionName)
            ->in($directory->userSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName));

        // Auf Mitglieder bestimmter Gruppen beschränken (falls konfiguriert).
        $query = GroupMembershipFilter::constrain(
            $query,
            GroupMembershipFilter::groupDns($directory, $connectionName)
        );

        $users = $query->paginate(500);

        $seen = [];

        foreach ($users as $ldapUser) {
            /** @var LdapUser $ldapUser */
            if ($this->syncSingleUser($directory, $connectionName, $ldapUser)) {
                $guid = $ldapUser->getConvertedGuid();
                if ($guid) {
                    $seen[] = $guid;
                }
            }
        }

        return $seen;
    }

    /**
     * Entfernt bzw. sperrt Benutzer dieses Verzeichnisses, die bei diesem Lauf
     * nicht mehr im Suchbereich (User DN / Group DN) auftauchten. Steuerung
     * über directory.stale_user_handling.
     *
     * @param  array<int, string>  $seenGuids
     */
    private function pruneStaleUsers(DirectoryModel $directory, array $seenGuids): int
    {
        $mode = $directory->stalePolicy();

        if ($mode === 'keep') {
            return 0;
        }

        // Sicherheitsnetz: hat die Suche (z.B. wegen falscher User DN) gar
        // nichts geliefert, wird NICHTS gelöscht.
        if ($seenGuids === []) {
            Log::warning('Stale-User-Bereinigung übersprungen: Synchronisierung lieferte keine Benutzer', [
                'directory_id' => $directory->id,
            ]);

            return 0;
        }

        $stale = DirectoryUser::query()
            ->where('directory_id', $directory->id)
            ->whereNotIn('object_guid', $seenGuids)
            ->get();

        $affected = 0;

        foreach ($stale as $directoryUser) {
            /** @var DirectoryUser $directoryUser */
            $user = $directoryUser->user;

            // Lokale Konten und Administratoren nie über die Sync anfassen.
            if ($user && ($user->auth_source !== 'active_directory' || $user->is_admin)) {
                continue;
            }

            DB::transaction(function () use ($mode, $directoryUser, $user) {
                if ($mode === 'delete') {
                    $directoryUser->groups()->detach();
                    $directoryUser->delete();
                    $user?->delete();
                } else { // disable
                    $directoryUser->forceFill(['account_status' => 'removed'])->save();
                    $user?->forceFill([
                        'is_active' => false,
                        'account_status' => 'removed',
                    ])->save();
                }
            });

            $affected++;
        }

        if ($affected > 0) {
            Log::info('Stale-User-Bereinigung', [
                'directory_id' => $directory->id,
                'mode' => $mode,
                'affected' => $affected,
            ]);
        }

        return $affected;
    }

    /**
     * Syncs a single AD user (used for full sync and for the "sync on every
     * login" hook) and mirrors it into the local users table with resolved
     * group-to-role mappings.
     */
    public function syncSingleUser(DirectoryModel $directory, string $connectionName, LdapUser $ldapUser): ?User
    {
        $guid = $ldapUser->getConvertedGuid();
        if (! $guid) {
            return null;
        }

        $this->trackUsn($ldapUser);

        return DB::transaction(function () use ($directory, $connectionName, $ldapUser, $guid) {
            $sam = $ldapUser->getFirstAttribute('samaccountname');
            $upn = $ldapUser->getFirstAttribute('userprincipalname');
            $mail = $ldapUser->getFirstAttribute('mail');
            $displayName = $ldapUser->getFirstAttribute('displayname') ?? $ldapUser->getFirstAttribute('cn');
            $accountStatus = $ldapUser->isDisabled() ? 'disabled' : 'enabled';

            $directoryUser = DirectoryUser::updateOrCreate(
                ['directory_id' => $directory->id, 'object_guid' => $guid],
                [
                    'sid' => $ldapUser->getConvertedSid(),
                    'sam_account_name' => $sam,
                    'upn' => $upn,
                    'first_name' => $ldapUser->getFirstAttribute('givenname'),
                    'last_name' => $ldapUser->getFirstAttribute('sn'),
                    'display_name' => $displayName,
                    'email' => $mail,
                    'phone' => $ldapUser->getFirstAttribute('telephonenumber'),
                    'department' => $ldapUser->getFirstAttribute('department'),
                    'company' => $ldapUser->getFirstAttribute('company'),
                    'position' => $ldapUser->getFirstAttribute('title'),
                    'office' => $ldapUser->getFirstAttribute('physicaldeliveryofficename'),
                    'manager' => $ldapUser->getFirstAttribute('manager'),
                    'distinguished_name' => $ldapUser->getDn(),
                    'domain' => $directory->domain,
                    'account_status' => $accountStatus,
                    'extra_attributes' => $this->extraAttributes($ldapUser),
                    'last_synced_at' => now(),
                ]
            );

            $groupIds = $this->syncUserGroupMemberships($directory, $connectionName, $ldapUser, $directoryUser);

            $roles = $this->resolveRoles($directory, $groupIds);

            $user = User::updateOrCreate(
                ['object_guid' => $guid],
                [
                    'username' => $sam,
                    'first_name' => $ldapUser->getFirstAttribute('givenname'),
                    'last_name' => $ldapUser->getFirstAttribute('sn'),
                    'name' => $displayName ?: $sam,
                    'email' => $mail ?: "{$sam}@{$directory->domain}",
                    'auth_source' => 'active_directory',
                    'is_active' => $accountStatus === 'enabled',
                    'directory_id' => $directory->id,
                    'sid' => $ldapUser->getConvertedSid(),
                    'sam_account_name' => $sam,
                    'upn' => $upn,
                    'display_name' => $displayName,
                    'phone' => $ldapUser->getFirstAttribute('telephonenumber'),
                    'department' => $ldapUser->getFirstAttribute('department'),
                    'company' => $ldapUser->getFirstAttribute('company'),
                    'position' => $ldapUser->getFirstAttribute('title'),
                    'office' => $ldapUser->getFirstAttribute('physicaldeliveryofficename'),
                    'manager' => $ldapUser->getFirstAttribute('manager'),
                    'distinguished_name' => $ldapUser->getDn(),
                    'domain' => $directory->domain,
                    'account_status' => $accountStatus,
                    'extra_attributes' => $this->extraAttributes($ldapUser),
                    'roles' => $roles,
                    'last_synced_at' => now(),
                ]
            );

            $directoryUser->forceFill(['user_id' => $user->id])->save();

            return $user;
        });
    }

    /**
     * Rollen des Benutzers aus den Gruppen-zu-Rollen-Mappings: verknüpfte
     * Gruppen (per ID) plus frei eingetragene Namen (per Gruppenname,
     * gross-/kleinschreibungsunabhängig, optional aufs Verzeichnis beschränkt).
     *
     * @param  array<int>  $groupIds
     * @return array<int, string>
     */
    public function resolveRoles(DirectoryModel $directory, array $groupIds): array
    {
        $groupNames = DirectoryGroup::whereIn('id', $groupIds)
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter()
            ->values()
            ->all();

        return GroupRoleMapping::query()
            ->where(function ($query) use ($groupIds, $groupNames, $directory) {
                $query->when($groupIds !== [], fn ($q) => $q->orWhereIn('directory_group_id', $groupIds));

                if ($groupNames !== []) {
                    $query->orWhere(function ($q) use ($groupNames, $directory) {
                        $q->whereNotNull('group_name')
                            ->whereIn(DB::raw('LOWER(group_name)'), $groupNames)
                            ->where(fn ($q2) => $q2->whereNull('directory_id')->orWhere('directory_id', $directory->id));
                    });
                }
            })
            ->pluck('role')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int> IDs of directory_groups the user (directly or via
     *                    nested membership) belongs to.
     */
    private function syncUserGroupMemberships(DirectoryModel $directory, string $connectionName, LdapUser $ldapUser, DirectoryUser $directoryUser): array
    {
        $direct = $ldapUser->groups()->get();
        $nested = $ldapUser->groups()->recursive()->get();

        $directGuids = $direct->map(fn (LdapGroup $g) => $g->getConvertedGuid())->filter()->all();

        $sync = [];
        $groupIds = [];

        foreach ($nested as $ldapGroup) {
            /** @var LdapGroup $ldapGroup */
            $guid = $ldapGroup->getConvertedGuid();
            if (! $guid) {
                continue;
            }

            $localGroup = DirectoryGroup::firstOrCreate(
                ['directory_id' => $directory->id, 'object_guid' => $guid],
                [
                    'sid' => $ldapGroup->getConvertedSid(),
                    'name' => $ldapGroup->getFirstAttribute('cn'),
                    'distinguished_name' => $ldapGroup->getDn(),
                    'description' => $ldapGroup->getFirstAttribute('description'),
                ]
            );

            $sync[$localGroup->id] = [
                'is_nested' => ! in_array($guid, $directGuids, true),
                'synced_at' => now(),
            ];
            $groupIds[] = $localGroup->id;
        }

        $directoryUser->groups()->sync($sync);

        return $groupIds;
    }

    private function extraAttributes(Model $model): array
    {
        $known = [
            'samaccountname', 'userprincipalname', 'givenname', 'sn', 'displayname', 'cn',
            'mail', 'telephonenumber', 'department', 'company', 'title',
            'physicaldeliveryofficename', 'manager', 'objectguid', 'objectsid', 'description',
            'distinguishedname', 'dn', 'useraccountcontrol', 'memberof',
        ];

        $attributes = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (in_array(strtolower($key), $known, true)) {
                continue;
            }

            $value = is_array($value) ? (count($value) === 1 ? $value[0] : $value) : $value;

            $attributes[$key] = $this->sanitizeForJson($value);
        }

        return $attributes;
    }

    private function sanitizeForJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->sanitizeForJson($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return 'base64:'.base64_encode($value);
    }
}
