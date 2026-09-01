<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verknüpft die Laravel-Session (Tabelle `sessions`) mit fachlichen
 * Metadaten (Gerät, Browser, Login-Methode) in `user_sessions`, damit
 * Benutzer und Administratoren aktive Sessions einsehen und widerrufen
 * können (promt.md Abschnitt 28).
 */
class SessionTracker
{
    public function record(User $user, Request $request, string $loginMethod): UserSession
    {
        $sessionId = $request->session()->getId();
        [$browser, $platform, $device] = $this->parseUserAgent((string) $request->userAgent());

        return UserSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device' => $device,
                'browser' => $browser,
                'platform' => $platform,
                'login_method' => $loginMethod,
                'login_at' => now(),
                'last_activity_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    public function touch(Request $request): void
    {
        UserSession::where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->update(['last_activity_at' => now()]);
    }

    /**
     * Widerruft eine Session: markiert sie in `user_sessions` und löscht
     * den zugehörigen Eintrag aus der Laravel-`sessions`-Tabelle, sodass
     * die Session beim nächsten Request tatsächlich ungültig ist.
     */
    public function revoke(UserSession $userSession): void
    {
        $userSession->forceFill(['revoked_at' => now()])->save();

        DB::table('sessions')->where('id', $userSession->session_id)->delete();
    }

    public function revokeAllForUserExcept(User $user, ?string $exceptSessionId): void
    {
        $query = UserSession::where('user_id', $user->id)->whereNull('revoked_at');

        if ($exceptSessionId) {
            $query->where('session_id', '!=', $exceptSessionId);
        }

        $sessionIds = $query->pluck('session_id');

        $query->update(['revoked_at' => now()]);

        if ($sessionIds->isNotEmpty()) {
            DB::table('sessions')->whereIn('id', $sessionIds)->delete();
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [browser, platform, device]
     */
    private function parseUserAgent(?string $userAgent): array
    {
        if (! $userAgent) {
            return [null, null, null];
        }

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => 'Safari',
            default => null,
        };

        $device = match (true) {
            str_contains($userAgent, 'Mobile') => 'Mobil',
            str_contains($userAgent, 'Tablet') => 'Tablet',
            default => 'Desktop',
        };

        return [$browser, $platform, $device];
    }
}
