<?php

namespace App\Scim;

use App\Jobs\SyncScimConnections;
use App\Models\AuditLog;
use App\Models\DirectoryGroup;
use App\Models\ScimConnection;
use App\Models\ScimResource;
use App\Models\User;
use App\Services\AccessPolicyEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Gleicht Benutzer und Gruppen mit einer Zielanwendung per SCIM ab. Bereitgestellt
 * werden aktive Benutzer, die laut Zugriffsregeln auf die Anwendung zugreifen
 * dürfen. Wer den Zugriff verliert, wird deaktiviert oder gelöscht.
 */
class ScimSyncService
{
    public static function markDirty(): void
    {
        if (! ScimConnection::anyActive() || ! Cache::add('scim.sync_pending', 1, 60)) {
            return;
        }

        SyncScimConnections::dispatch()->delay(now()->addSeconds(30));
    }

    /**
     * @return array{created: int, updated: int, removed: int, errors: array<int, string>}
     */
    public function sync(ScimConnection $connection): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'removed' => 0, 'errors' => []];
        $client = new ScimClient($connection);
        $applicationId = $connection->application_id;

        $desiredUsers = $connection->sync_users
            ? User::query()->where('is_active', true)->get()
                ->filter(fn (User $user) => AccessPolicyEvaluator::passesPolicies($applicationId, $user))
                ->keyBy('id')
            : collect();

        $this->syncUsers($connection, $client, $desiredUsers, $stats);

        if ($connection->sync_users && $connection->sync_groups) {
            $this->syncGroups($connection, $client, $desiredUsers, $stats);
        }

        $failed = $stats['errors'] !== [];

        $connection->forceFill([
            'last_synced_at' => now(),
            'last_status' => $failed ? 'error' : 'ok',
            'last_error' => $failed ? implode("\n", array_slice($stats['errors'], 0, 5)) : null,
        ])->save();

        if ($failed) {
            AuditLog::record('scim.sync.failed', null, ['errors' => count($stats['errors'])], $connection->application);
        } elseif ($stats['created'] + $stats['updated'] + $stats['removed'] > 0) {
            AuditLog::record('scim.sync.completed', null, array_diff_key($stats, ['errors' => 1]), $connection->application);
        }

        return $stats;
    }

    /**
     * @param  Collection<int, User>  $desiredUsers
     */
    private function syncUsers(ScimConnection $connection, ScimClient $client, Collection $desiredUsers, array &$stats): void
    {
        $tracked = $connection->resources()->where('resource_type', ScimResource::USER)->get()->keyBy('local_id');

        foreach ($desiredUsers as $user) {
            $payload = $this->userPayload($user);
            $hash = hash('sha256', json_encode($payload));
            $resource = $tracked->get($user->id);

            try {
                if (! $resource) {
                    $created = $client->create('Users', $payload);
                    $connection->resources()->create([
                        'resource_type' => ScimResource::USER,
                        'local_id' => $user->id,
                        'remote_id' => $created['id'],
                        'payload_hash' => $hash,
                        'synced_at' => now(),
                    ]);
                    $stats['created']++;
                } elseif ($resource->payload_hash !== $hash || $resource->deactivated) {
                    $client->replace('Users', $resource->remote_id, $payload);
                    $resource->forceFill(['payload_hash' => $hash, 'deactivated' => false, 'synced_at' => now()])->save();
                    $stats['updated']++;
                }
            } catch (ScimException $e) {
                $stats['errors'][] = "Benutzer {$user->username}: ".$e->getMessage();
            }
        }

        foreach ($tracked as $localId => $resource) {
            if ($desiredUsers->has($localId)) {
                continue;
            }

            try {
                if ($connection->on_removal === ScimConnection::REMOVAL_DELETE) {
                    $client->delete('Users', $resource->remote_id);
                    $resource->delete();
                    $stats['removed']++;
                } elseif (! $resource->deactivated) {
                    $client->setActive($resource->remote_id, false);
                    $resource->forceFill(['deactivated' => true, 'synced_at' => now()])->save();
                    $stats['removed']++;
                }
            } catch (ScimException $e) {
                $stats['errors'][] = "Benutzer #{$localId} entfernen: ".$e->getMessage();
            }
        }
    }

    /**
     * @param  Collection<int, User>  $desiredUsers
     */
    private function syncGroups(ScimConnection $connection, ScimClient $client, Collection $desiredUsers, array &$stats): void
    {
        $remoteUserIds = $connection->resources()
            ->where('resource_type', ScimResource::USER)
            ->where('deactivated', false)
            ->pluck('remote_id', 'local_id');

        $tracked = $connection->resources()->where('resource_type', ScimResource::GROUP)->get()->keyBy('local_id');
        $desiredGroupIds = [];

        foreach (DirectoryGroup::query()->with('directoryUsers')->get() as $group) {
            $members = [];

            foreach ($group->directoryUsers as $directoryUser) {
                $remoteId = $directoryUser->user_id ? $remoteUserIds->get($directoryUser->user_id) : null;

                if ($remoteId && $desiredUsers->has($directoryUser->user_id)) {
                    $members[$remoteId] = ['value' => $remoteId];
                }
            }

            if ($members === []) {
                continue;
            }

            ksort($members);
            $desiredGroupIds[] = $group->id;

            $payload = [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
                'externalId' => 'group-'.$group->id,
                'displayName' => $group->name,
                'members' => array_values($members),
            ];
            $hash = hash('sha256', json_encode($payload));
            $resource = $tracked->get($group->id);

            try {
                if (! $resource) {
                    $created = $client->create('Groups', $payload);
                    $connection->resources()->create([
                        'resource_type' => ScimResource::GROUP,
                        'local_id' => $group->id,
                        'remote_id' => $created['id'],
                        'payload_hash' => $hash,
                        'synced_at' => now(),
                    ]);
                    $stats['created']++;
                } elseif ($resource->payload_hash !== $hash) {
                    $client->replace('Groups', $resource->remote_id, $payload);
                    $resource->forceFill(['payload_hash' => $hash, 'synced_at' => now()])->save();
                    $stats['updated']++;
                }
            } catch (ScimException $e) {
                $stats['errors'][] = "Gruppe {$group->name}: ".$e->getMessage();
            }
        }

        foreach ($tracked as $localId => $resource) {
            if (in_array($localId, $desiredGroupIds, true)) {
                continue;
            }

            try {
                $client->delete('Groups', $resource->remote_id);
                $resource->delete();
                $stats['removed']++;
            } catch (ScimException $e) {
                $stats['errors'][] = "Gruppe #{$localId} entfernen: ".$e->getMessage();
            }
        }
    }

    public function userPayload(User $user): array
    {
        $displayName = $user->display_name ?: ($user->name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')));
        $displayName = $displayName !== '' ? $displayName : $user->username;

        $payload = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'externalId' => (string) $user->id,
            'userName' => $user->username ?: $user->email,
            'name' => array_filter([
                'givenName' => $user->first_name,
                'familyName' => $user->last_name,
                'formatted' => $displayName,
            ]),
            'displayName' => $displayName,
            'title' => $user->position,
            'emails' => $user->email ? [['value' => $user->email, 'type' => 'work', 'primary' => true]] : [],
            'phoneNumbers' => $user->phone ? [['value' => $user->phone, 'type' => 'work']] : [],
            'active' => true,
        ];

        return array_filter($payload, fn ($value) => $value !== null && $value !== []);
    }
}
