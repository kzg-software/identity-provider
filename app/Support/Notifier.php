<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Legt Benachrichtigungen für die Glocke in der Navigationsleiste an.
 *
 * Persönliche Ereignisse (Sicherheitseinstellungen o. Ä.) gehen an genau
 * einen Benutzer; Systemhinweise werden per Fan-out an alle Administratoren
 * verteilt. Ein optionaler dedupe_key verhindert Wiederholungen.
 */
class Notifier
{
    /**
     * @param  array{level?: string, body?: string|null, action_url?: string|null, dedupe_key?: string|null}  $options
     */
    public static function toUser(User $user, string $type, string $title, array $options = []): Notification
    {
        $attributes = [
            'type' => $type,
            'level' => $options['level'] ?? 'info',
            'title' => $title,
            'body' => $options['body'] ?? null,
            'action_url' => $options['action_url'] ?? null,
        ];

        $key = $options['dedupe_key'] ?? null;

        if ($key !== null) {
            return Notification::firstOrCreate(
                ['user_id' => $user->id, 'dedupe_key' => $key],
                $attributes,
            );
        }

        return $user->notifications()->create($attributes);
    }

    /**
     * @param  array{level?: string, body?: string|null, action_url?: string|null, dedupe_key?: string|null}  $options
     */
    public static function toAdmins(string $type, string $title, array $options = []): void
    {
        self::admins()->each(fn (User $admin) => self::toUser($admin, $type, $title, $options));
    }

    /**
     * Aktuell aktive Administratoren.
     *
     * @return Collection<int, User>
     */
    public static function admins(): Collection
    {
        return User::query()->where('is_admin', true)->where('is_active', true)->get();
    }
}
