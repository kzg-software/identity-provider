<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E-Mail-Versand (SMTP) aus den Systemeinstellungen. Ohne konfigurierten
 * Host bleibt der in .env gesetzte Mailer aktiv (Standard: "log").
 */
class MailSettings
{
    public const KEYS = [
        'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption',
        'mail_username', 'mail_from_address', 'mail_from_name',
    ];

    public static function configured(): bool
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return false;
            }

            return SystemSetting::get('mail_enabled') === '1'
                && filled(SystemSetting::get('mail_host'));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Überträgt die gespeicherten SMTP-Werte in die Laufzeit-Konfiguration.
     * Wird beim Booten aufgerufen.
     */
    public static function apply(): void
    {
        if (! self::configured()) {
            return;
        }

        $encryption = SystemSetting::get('mail_encryption') ?: 'starttls';
        $port = (int) (SystemSetting::get('mail_port') ?: ($encryption === 'ssl' ? 465 : 587));

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => SystemSetting::get('mail_host'),
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => SystemSetting::get('mail_username') ?: null,
            'mail.mailers.smtp.password' => Secret::decrypt(SystemSetting::get('mail_password')) ?: null,
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.from.address' => SystemSetting::get('mail_from_address') ?: 'no-reply@localhost',
            'mail.from.name' => SystemSetting::get('mail_from_name') ?: (SystemSetting::get('system_name') ?: config('app.name')),
        ]);
    }

    /** Absenderadresse für ausgehende Mails (auch ohne SMTP-Konfiguration sinnvoll). */
    public static function fromAddress(): string
    {
        try {
            return SystemSetting::get('mail_from_address') ?: (string) config('mail.from.address', 'no-reply@localhost');
        } catch (Throwable) {
            return 'no-reply@localhost';
        }
    }
}
