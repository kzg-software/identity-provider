<?php

namespace App\Support;

use App\Mail\SystemMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Legt Benachrichtigungen für die Glocke in der Navigationsleiste an und
 * schickt sie bei Bedarf zusätzlich per E-Mail.
 *
 * Persönliche Ereignisse gehen an genau einen Benutzer; Systemhinweise
 * werden per Fan-out an alle Administratoren verteilt. Ein optionaler
 * dedupe_key verhindert Wiederholungen.
 *
 * E-Mail: Sicherheitsrelevante Hinweise (Option "security") gehen immer auch
 * per E-Mail. Alle anderen nur, wenn der Benutzer das in seinen
 * Kontoeinstellungen erlaubt hat.
 */
class Notifier
{
    /**
     * @param  array{level?: string, body?: string|null, action_url?: string|null, dedupe_key?: string|null, security?: bool}  $options
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

        $note = $key !== null
            ? Notification::firstOrCreate(['user_id' => $user->id, 'dedupe_key' => $key], $attributes)
            : $user->notifications()->create($attributes);

        if ($note->wasRecentlyCreated) {
            self::maybeSendEmail($user, $note, (bool) ($options['security'] ?? false));
        }

        return $note;
    }

    /**
     * @param  array{level?: string, body?: string|null, action_url?: string|null, dedupe_key?: string|null, security?: bool}  $options
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

    private static function maybeSendEmail(User $user, Notification $note, bool $security): void
    {
        if (blank($user->email) || ! MailSettings::configured()) {
            return;
        }

        $category = NotificationCategories::forType($note->type);

        if (! $security && ! $user->wantsEmailFor($category)) {
            return;
        }

        // SystemMail ist ShouldQueue: geht sofort in die Warteschlange, der
        // eigentliche SMTP-Versand passiert im Hintergrund.
        try {
            Mail::to($user->email)->send(new SystemMail(
                subjectLine: $note->title,
                heading: $note->title,
                body: array_values(array_filter([$note->body])),
                actionUrl: $note->action_url,
                actionLabel: $note->action_url ? 'Öffnen' : null,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
