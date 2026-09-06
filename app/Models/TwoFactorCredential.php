<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'totp_secret', 'totp_confirmed_at', 'recovery_codes',
])]
class TwoFactorCredential extends Model
{
    protected function casts(): array
    {
        return [
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'recovery_codes' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Erzeugt einen frischen Satz Wiederherstellungscodes (ohne mehrdeutige
     * Zeichen). Gilt für alle Zwei-Faktor-Methoden des Kontos.
     *
     * @return array<int, string>
     */
    public static function newRecoveryCodes(int $count = 8): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($c = 0; $c < 10; $c++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                if ($c === 4) {
                    $code .= '-';
                }
            }
            $codes[] = $code;
        }

        return $codes;
    }

    public function hasTotp(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        return array_values(array_filter((array) ($this->recovery_codes ?? [])));
    }

    /**
     * Prüft einen Wiederherstellungscode und verbraucht ihn bei Erfolg
     * dauerhaft. Vergleich in konstanter Zeit.
     */
    public function useRecoveryCode(string $code): bool
    {
        $code = trim($code);
        $remaining = [];
        $matched = false;

        foreach ($this->recoveryCodes() as $stored) {
            if (! $matched && hash_equals($stored, $code)) {
                $matched = true;

                continue;
            }

            $remaining[] = $stored;
        }

        if ($matched) {
            $this->forceFill(['recovery_codes' => $remaining])->save();
        }

        return $matched;
    }
}
