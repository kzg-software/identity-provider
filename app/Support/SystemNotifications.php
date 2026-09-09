<?php

namespace App\Support;

use App\Directory\DirectoryTestService;
use App\Models\AuditLog;
use App\Models\Directory;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Services\ExpiryWarningService;
use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Leitet aus dem laufenden Systemzustand die für Administratoren relevanten
 * Benachrichtigungen ab (Updates, ablaufende Zertifikate, fehlgeschlagene
 * Sicherungen, Scheduler, Verzeichnisse, auffällige Anmeldungen) und gleicht
 * sie mit den gespeicherten Benachrichtigungen ab:
 *
 *   - neue Bedingung  -> Benachrichtigung anlegen (einmalig je dedupe_key)
 *   - Bedingung weg   -> noch ungelesene Systembenachrichtigung entfernen
 *
 * So bleibt die Glocke aktuell, auch ohne dass ein Admin etwas wegklickt.
 */
class SystemNotifications
{
    private const SYNC_MARKER = 'notifications:last_system_sync';

    private const STALE_AFTER_MINUTES = 15;

    private const KEY_PREFIX = 'sys:';

    /** Gelesene Systemhinweise werden nach so vielen Tagen entfernt. */
    private const KEEP_READ_DAYS_SYSTEM = 7;

    /** Gelesene persönliche/Sicherheits-Hinweise werden nach so vielen Tagen entfernt. */
    private const KEEP_READ_DAYS_PERSONAL = 30;

    /** Nach so vielen Tagen wird jede Benachrichtigung entfernt, auch ungelesene. */
    private const HARD_DELETE_DAYS = 180;

    public static function isStale(): bool
    {
        $last = Cache::get(self::SYNC_MARKER);

        return ! $last || now()->diffInMinutes($last) >= self::STALE_AFTER_MINUTES;
    }

    public static function syncIfStale(): void
    {
        if (self::isStale()) {
            self::sync();
        }
    }

    public static function sync(): void
    {
        Cache::put(self::SYNC_MARKER, now()->toDateTimeString(), now()->addHours(6));

        self::pruneReadNotifications();

        $alerts = self::currentAlerts();
        $admins = Notifier::admins();

        if ($admins->isEmpty()) {
            return;
        }

        $activeKeys = array_column($alerts, 'dedupe_key');

        foreach ($admins as $admin) {
            foreach ($alerts as $alert) {
                Notifier::toUser($admin, $alert['type'], $alert['title'], [
                    'level' => $alert['level'],
                    'body' => $alert['body'] ?? null,
                    'action_url' => $alert['action_url'] ?? null,
                    'dedupe_key' => $alert['dedupe_key'],
                ]);
            }
        }

        Notification::query()
            ->whereIn('user_id', $admins->pluck('id'))
            ->whereNull('read_at')
            ->where('dedupe_key', 'like', self::KEY_PREFIX.'%')
            ->when($activeKeys !== [], fn ($q) => $q->whereNotIn('dedupe_key', $activeKeys))
            ->delete();
    }

    /**
     * Räumt alte Benachrichtigungen (aller Konten) auf:
     *
     *   - gelesene Systemhinweise nach {@see KEEP_READ_DAYS_SYSTEM} Tagen
     *   - übrige gelesene Hinweise nach {@see KEEP_READ_DAYS_PERSONAL} Tagen
     *   - alles (auch ungelesen) nach {@see HARD_DELETE_DAYS} Tagen
     */
    public static function pruneReadNotifications(): int
    {
        $deleted = Notification::query()
            ->whereNotNull('read_at')
            ->where('type', 'like', 'system.%')
            ->where('read_at', '<', now()->subDays(self::KEEP_READ_DAYS_SYSTEM))
            ->delete();

        $deleted += Notification::query()
            ->whereNotNull('read_at')
            ->where('type', 'not like', 'system.%')
            ->where('read_at', '<', now()->subDays(self::KEEP_READ_DAYS_PERSONAL))
            ->delete();

        $deleted += Notification::query()
            ->where('created_at', '<', now()->subDays(self::HARD_DELETE_DAYS))
            ->delete();

        return $deleted;
    }

    /**
     * @return array<int, array{type: string, level: string, title: string, body: ?string, action_url: ?string, dedupe_key: string}>
     */
    public static function currentAlerts(): array
    {
        $alerts = [];

        try {
            if (UpdateChecker::enabled()) {
                $status = UpdateChecker::status();

                if ($status['update_available'] && $status['latest']) {
                    $alerts[] = [
                        'type' => 'system.update',
                        'level' => 'warning',
                        'title' => 'Update verfügbar: '.$status['latest'],
                        'body' => 'Installiert ist '.$status['current'].'. Details und Changelog unter „Aktualisierungen".',
                        'action_url' => self::route('admin.updates.index'),
                        'dedupe_key' => self::KEY_PREFIX.'update:'.$status['latest'],
                    ];
                }
            }
        } catch (Throwable) {
        }

        try {
            foreach (ExpiryWarningService::warnings() as $warning) {
                $alerts[] = [
                    'type' => 'system.expiry',
                    'level' => ($warning['level'] ?? 'warn') === 'fail' ? 'critical' : 'warning',
                    'title' => $warning['label'],
                    'body' => $warning['detail'] ?? null,
                    'action_url' => $warning['url'] ?? null,
                    'dedupe_key' => self::KEY_PREFIX.'expiry:'.md5($warning['label']),
                ];
            }
        } catch (Throwable) {
        }

        try {
            $lastError = SystemSetting::get('auto_backup_last_error');

            if (filled($lastError)) {
                $lastRun = SystemSetting::get('auto_backup_last_run');
                $alerts[] = [
                    'type' => 'system.backup',
                    'level' => 'critical',
                    'title' => 'Automatische Sicherung fehlgeschlagen',
                    'body' => Str::limit((string) $lastError, 160),
                    'action_url' => self::route('admin.backups.index'),
                    'dedupe_key' => self::KEY_PREFIX.'backup:'.md5($lastRun.'|'.$lastError),
                ];
            }
        } catch (Throwable) {
        }

        try {
            $heartbeat = Cache::get('schedule.heartbeat');

            if (! $heartbeat || now()->diffInMinutes($heartbeat) > 15) {
                $alerts[] = [
                    'type' => 'system.scheduler',
                    'level' => 'warning',
                    'title' => 'Aufgabenplaner scheint nicht zu laufen',
                    'body' => $heartbeat
                        ? 'Letztes Lebenszeichen: '.$heartbeat.'.'
                        : 'Es wurde noch kein Lebenszeichen empfangen. Läuft „php artisan schedule:run" per Cron / Aufgabenplanung?',
                    'action_url' => self::route('admin.status.index'),
                    'dedupe_key' => self::KEY_PREFIX.'scheduler',
                ];
            }
        } catch (Throwable) {
        }

        try {
            foreach (Directory::where('is_active', true)->get() as $directory) {
                $result = (new DirectoryTestService)->testConnection($directory);

                if (! ($result['ok'] ?? false)) {
                    $alerts[] = [
                        'type' => 'system.directory',
                        'level' => 'critical',
                        'title' => 'Verzeichnis nicht erreichbar: '.$directory->name,
                        'body' => Str::limit((string) ($result['message'] ?? ''), 160) ?: null,
                        'action_url' => self::route('admin.directories.show', $directory),
                        'dedupe_key' => self::KEY_PREFIX.'directory:'.$directory->id,
                    ];
                }
            }
        } catch (Throwable) {
        }

        try {
            $failed = AuditLog::where('event', 'login.failed')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($failed >= 25) {
                $alerts[] = [
                    'type' => 'system.logins',
                    'level' => 'warning',
                    'title' => 'Viele fehlgeschlagene Anmeldungen',
                    'body' => $failed.' fehlgeschlagene Anmeldungen in der letzten Stunde. Möglicher Angriffsversuch.',
                    'action_url' => self::route('admin.audit-log.index'),
                    'dedupe_key' => self::KEY_PREFIX.'logins:'.now()->format('Y-m-d-H'),
                ];
            }
        } catch (Throwable) {
        }

        return $alerts;
    }

    private static function route(string $name, mixed $param = null): ?string
    {
        try {
            return Route::has($name) ? route($name, $param ?? []) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
