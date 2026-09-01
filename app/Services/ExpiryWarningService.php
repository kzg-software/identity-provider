<?php

namespace App\Services;

use App\Models\OidcKey;
use App\Models\SamlCertificate;

class ExpiryWarningService
{
    public const WARN_WITHIN_DAYS = 30;

    /**
     * @return array<int, array{label: string, detail: string, level: string, url: string}>
     */
    public static function warnings(): array
    {
        $warnings = [];

        $cert = SamlCertificate::where('type', 'signing')->where('is_active', true)->orderByDesc('created_at')->first();

        if (! $cert) {
            $warnings[] = [
                'label' => 'Kein aktives SAML-Signing-Zertifikat',
                'detail' => 'Es ist kein aktives SAML-Zertifikat hinterlegt.',
                'level' => 'fail',
                'url' => route('admin.saml-certificates.index'),
            ];
        } elseif ($cert->expires_at) {
            $daysLeft = now()->diffInDays($cert->expires_at, false);

            if ($daysLeft < 0) {
                $warnings[] = [
                    'label' => 'SAML-Zertifikat abgelaufen',
                    'detail' => 'Abgelaufen am '.$cert->expires_at->toDateString().'.',
                    'level' => 'fail',
                    'url' => route('admin.saml-certificates.index'),
                ];
            } elseif ($daysLeft < self::WARN_WITHIN_DAYS) {
                $warnings[] = [
                    'label' => 'SAML-Zertifikat läuft bald ab',
                    'detail' => "Läuft in {$daysLeft} Tagen ab ({$cert->expires_at->toDateString()}).",
                    'level' => 'warn',
                    'url' => route('admin.saml-certificates.index'),
                ];
            }
        }

        $key = OidcKey::where('is_active', true)->orderByDesc('rotated_at')->first();

        if (! $key) {
            $warnings[] = [
                'label' => 'Kein aktiver OIDC-Signing-Key',
                'detail' => 'Es ist kein aktiver OIDC-Signing-Key vorhanden.',
                'level' => 'fail',
                'url' => route('admin.oidc-keys.index'),
            ];
        } else {
            $ageDays = $key->rotated_at?->diffInDays(now()) ?? 0;

            if ($ageDays > 180) {
                $warnings[] = [
                    'label' => 'OIDC-Signing-Key sollte rotiert werden',
                    'detail' => "Aktiver Key ist {$ageDays} Tage alt.",
                    'level' => 'warn',
                    'url' => route('admin.oidc-keys.index'),
                ];
            }
        }

        return $warnings;
    }
}
