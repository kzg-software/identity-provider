<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Hält den Zwischenzustand zwischen bestandener Passwortprüfung und
 * bestandenem zweitem Faktor. Der Nutzer ist in dieser Phase noch NICHT
 * angemeldet – es liegt nur die (kurzlebige) Notiz in der Session, welches
 * Konto den zweiten Faktor noch nachweisen muss.
 */
class TwoFactorChallenge
{
    private const KEY = '2fa.pending';

    private const TTL_MINUTES = 5;

    public static function start(User $user, string $method): void
    {
        Session::put(self::KEY, [
            'user_id' => $user->getKey(),
            'method' => $method,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * @return array{user_id: int|string, method: string, expires_at: int}|null
     */
    public static function data(): ?array
    {
        $data = Session::get(self::KEY);

        if (! is_array($data) || ! isset($data['expires_at']) || $data['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        return $data;
    }

    public static function user(): ?User
    {
        $data = self::data();

        return $data ? User::find($data['user_id']) : null;
    }

    public static function method(): ?string
    {
        return self::data()['method'] ?? null;
    }

    public static function forget(): void
    {
        Session::forget(self::KEY);
    }
}
