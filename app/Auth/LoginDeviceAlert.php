<?php

namespace App\Auth;

use App\Models\User;
use App\Models\UserLoginDevice;
use App\Services\SessionTracker;
use App\Support\Notifier;
use App\Support\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Führt Buch über die Geräte, von denen sich ein Konto anmeldet (grob nach
 * Browser, Betriebssystem und Gerätetyp) und schickt genau einmal eine
 * E-Mail, wenn eine neue Kombination auftaucht.
 *
 * Bewusst zurückhaltend: bekannte Geräte lösen nichts aus, es wird nichts
 * abgemeldet, und pro Gerät kommt höchstens eine Meldung. Wird bei jeder
 * Anmeldung über SessionTracker::record aufgerufen.
 */
class LoginDeviceAlert
{
    public function record(User $user, Request $request, string $loginMethod): void
    {
        $signature = SessionTracker::deviceSignature($request->userAgent());
        $label = SessionTracker::deviceLabel($request->userAgent());

        /** @var UserLoginDevice $device */
        $device = UserLoginDevice::firstOrNew([
            'user_id' => $user->id,
            'signature' => $signature,
        ]);

        $isNew = ! $device->exists;

        $device->fill([
            'label' => $label,
            'last_ip_address' => $request->ip(),
            'last_seen_at' => now(),
            'first_seen_at' => $device->first_seen_at ?? now(),
            'login_count' => ($device->login_count ?? 0) + 1,
        ])->save();

        if (! $isNew || ! SecuritySettings::newDeviceEmailEnabled()) {
            return;
        }

        Notifier::toUser($user, 'security.new_device', 'Neue Anmeldung von einem neuen Gerät', [
            'level' => 'warning',
            'security' => true,
            'dedupe_key' => 'new-device:'.$user->id.':'.$signature,
            'action_url' => Route::has('profile.security') ? route('profile.security') : null,
            'body' => implode("\n", array_filter([
                'Gerät: '.$label,
                'IP-Adresse: '.($request->ip() ?? 'unbekannt'),
                'Zeitpunkt: '.now()->format('d.m.Y H:i').' Uhr',
                'Anmeldeart: '.$this->methodLabel($loginMethod),
                '',
                'Wenn Sie das waren, müssen Sie nichts tun. Andernfalls ändern Sie bitte'
                    .' Ihr Passwort und entfernen unter Profil, Sicherheit die vertrauten Geräte.',
            ])),
        ]);
    }

    private function methodLabel(string $method): string
    {
        return match (true) {
            str_contains($method, 'windows_sso') => 'Windows-Anmeldung',
            str_contains($method, 'active_directory') => 'Active-Directory-Konto',
            str_contains($method, 'passkey') => 'Passkey',
            str_contains($method, 'totp') => 'Passwort und Authenticator-App',
            str_contains($method, 'recovery') => 'Passwort und Wiederherstellungscode',
            default => 'Passwort',
        };
    }
}
