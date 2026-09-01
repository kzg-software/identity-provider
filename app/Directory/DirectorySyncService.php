<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\GroupRoleMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\Group as LdapGroup;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Throwable;

/**
 * Synchronises users and groups from an Active Directory / LDAP directory
 * into directory_users / directory_groups / directory_group_memberships and
 * mirrors the account into the local users table (auth_source=active_directory).
 */
class DirectorySyncService
{
    public function syncAll(DirectoryModel $directory): array
    {
        $start = microtime(true);

        try {
            DirectoryConnectionResolver::connect($directory);
            $name = DirectoryConnectionResolver::connectionName($directory);

            $groupCount = $this->syncGroups($directory, $name);
            $userCount = $this->syncUsers($directory, $name);

            $duration = (int) round(microtime(true) - $start);

            $directory->forceFill([
                'last_sync_at' => now(),
                'last_sync_duration_seconds' => $duration,
                'last_sync_user_count' => $userCount,
                'last_sync_group_count' => $groupCount,
                'last_sync_error' => null,
            ])->save();

            return [
                'ok' => true,
                'users' => $userCount,
                'groups' => $groupCount,
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

    private function syncGroups(DirectoryModel $directory, string $connectionName): int
    {
        $groups = LdapGroup::on($connectionName)
            ->in($directory->group_dn ?: $directory->base_dn)
            ->get();

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

            $count++;
        }

        return $count;
    }

    private function syncUsers(DirectoryModel $directory, string $connectionName): int
    {
        $users = LdapUser::on($connectionName)
            ->in($directory->user_dn ?: $directory->base_dn)
            ->get();

        $count = 0;

        foreach ($users as $ldapUser) {
            /** @var LdapUser $ldapUser */
            $this->syncSingleUser($directory, $connectionName, $ldapUser);
            $count++;
        }

        return $count;
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

            $roles = GroupRoleMapping::whereIn('directory_group_id', $groupIds)->pluck('role')->unique()->values()->all();

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
     * @return array<int> IDs of directory_groups the user (directly or via
     *                     nested membership) belongs to.
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

    private function extraAttributes(\LdapRecord\Models\Model $model): array
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
