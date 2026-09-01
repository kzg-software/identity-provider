<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_setting:{$key}", function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("system_setting:{$key}");
    }

    public static function isInstalled(): bool
    {
        return static::get('installed') === '1';
    }

    /**
     * Boolean-Einstellung. Fehlt der Schlüssel (oder ist die Tabelle noch
     * nicht da), gilt $default.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        try {
            $value = static::get($key);
        } catch (\Throwable) {
            return $default;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Ist die automatische Anmeldung per Windows (Kerberos/SPNEGO bzw. der
     * eingebaute NTLM-Endpunkt) aktiv? Standard: an.
     */
    public static function windowsSsoEnabled(): bool
    {
        return static::bool('windows_sso_enabled', true);
    }
}
