<?php

namespace App\Auth;

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\SessionTracker;
use App\Support\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Diesem Gerät vertrauen" auf der Zwei-Faktor-Seite: Nach bestandener
 * Challenge kann der Benutzer entscheiden, dass der zweite Faktor auf diesem
 * Gerät eine Weile (Standard 120 Tage) nicht mehr abgefragt wird.
 *
 * Im Cookie steht nur ein zufälliges Token, in der Datenbank dessen Hash.
 * Läuft der Eintrag ab oder wird er widerrufen, greift wieder die normale
 * Zwei-Faktor-Abfrage.
 */
class TrustedDevices
{
    public const COOKIE = 'trusted_device';

    /**
     * Ist das Gerät hinter dieser Anfrage für den Benutzer als vertraut
     * hinterlegt und noch gültig? Aktualisiert nebenbei "zuletzt genutzt".
     */
    public function isTrusted(User $user, Request $request): bool
    {
        if (! SecuritySettings::trustedDevicesEnabled()) {
            return false;
        }

        $device = $this->lookup($user, $request);

        if (! $device) {
            return false;
        }

        $device->forceFill([
            'last_used_at' => now(),
            'ip_address' => $request->ip(),
        ])->save();

        return true;
    }

    /**
     * Merkt das aktuelle Gerät als vertraut und schickt das Cookie mit der
     * nächsten Antwort mit.
     */
    public function remember(User $user, Request $request): void
    {
        if (! SecuritySettings::trustedDevicesEnabled()) {
            return;
        }

        $token = Str::random(48);
        $days = SecuritySettings::trustedDeviceDays();

        // Alte/abgelaufene Einträge aufräumen und die Zahl der vertrauten
        // Geräte pro Konto begrenzen.
        $user->trustedDevices()->where('expires_at', '<=', now())->delete();
        $keepIds = $user->trustedDevices()->latest()->limit(9)->pluck('id');
        if ($keepIds->isNotEmpty()) {
            $user->trustedDevices()->whereNotIn('id', $keepIds)->delete();
        }

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'label' => SessionTracker::deviceLabel($request->userAgent()),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: $days * 24 * 60,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        AuditLog::record('two_factor.device_trusted', $user, [
            'label' => SessionTracker::deviceLabel($request->userAgent()),
            'days' => $days,
        ]);
    }

    /**
     * Entfernt genau das Gerät hinter dieser Anfrage (z. B. beim Abmelden,
     * wenn der Nutzer das ausdrücklich will) und löscht das Cookie.
     */
    public function forgetCurrent(User $user, Request $request): void
    {
        $this->lookup($user, $request)?->delete();

        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * Widerruft alle vertrauten Geräte eines Kontos. Wird bei
     * Passwortänderung und beim Abmelden aller anderen Sitzungen aufgerufen.
     */
    public function revokeAll(User $user): int
    {
        $count = $user->trustedDevices()->count();

        $user->trustedDevices()->delete();

        if ($count > 0) {
            AuditLog::record('two_factor.devices_untrusted', $user, ['count' => $count]);
        }

        return $count;
    }

    private function lookup(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $user->trustedDevices()
            ->active()
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }
}
