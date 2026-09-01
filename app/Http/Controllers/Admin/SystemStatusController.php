<?php

namespace App\Http\Controllers\Admin;

use App\Directory\DirectoryTestService;
use App\Http\Controllers\Controller;
use App\Models\Directory;
use App\Models\OidcKey;
use App\Models\SamlCertificate;
use App\Models\UserSession;
use App\Services\ExpiryWarningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SystemStatusController extends Controller
{
    public function index(): View
    {
        $checks = [
            $this->check('Datenbank', fn () => DB::connection()->getPdo() !== null),
            $this->check('Cache', function () {
                Cache::put('health_check', '1', 5);

                return Cache::get('health_check') === '1';
            }),
            $this->check('Dateisystem (storage/)', fn () => is_writable(storage_path())),
            $this->check('Dateisystem (bootstrap/cache/)', fn () => is_writable(base_path('bootstrap/cache'))),
            $this->check('Queue', function () {
                DB::table('jobs')->count();

                return 'ok';
            }),
            $this->checkScheduler(),
            $this->checkSessions(),
            $this->check('Windows-SSO-Middleware', fn () => 'ok', 'Erwartet REMOTE_USER/AUTH_USER vom Webserver (IIS/Apache mod_auth_gssapi/Nginx SPNEGO); kein Login ohne echten Header.'),
            $this->checkOidcKey(),
            $this->checkSamlCertificate(),
        ];

        $directoryChecks = Directory::where('is_active', true)->get()->map(function (Directory $directory) {
            $result = (new DirectoryTestService)->testConnection($directory);

            return [
                'label' => "AD/LDAP: {$directory->name}",
                'status' => $result['ok'] ? 'ok' : 'fail',
                'detail' => $result['message'],
            ];
        })->all();

        return view('admin.status.index', [
            'checks' => array_merge($checks, $directoryChecks),
        ]);
    }

    private function check(string $label, callable $fn, ?string $detail = null): array
    {
        try {
            $result = $fn();
            $status = is_string($result) ? $result : ($result ? 'ok' : 'fail');

            return ['label' => $label, 'status' => $status, 'detail' => $detail];
        } catch (Throwable $e) {
            return ['label' => $label, 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    private function checkScheduler(): array
    {
        $lastHeartbeat = Cache::get('schedule.heartbeat');

        if (! $lastHeartbeat) {
            return [
                'label' => 'Scheduler',
                'status' => 'warn',
                'detail' => 'Kein Scheduler-Heartbeat gefunden. Läuft "php artisan schedule:run" per Cron/Task Scheduler?',
            ];
        }

        $ok = now()->diffInMinutes($lastHeartbeat) < 5;

        return [
            'label' => 'Scheduler',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => 'Letzter Heartbeat: '.$lastHeartbeat,
        ];
    }

    private function checkSessions(): array
    {
        return [
            'label' => 'Aktive Sessions',
            'status' => 'ok',
            'detail' => (string) UserSession::whereNull('revoked_at')->count(),
        ];
    }

    private function checkOidcKey(): array
    {
        $active = OidcKey::where('is_active', true)->orderByDesc('rotated_at')->first();

        if (! $active) {
            return ['label' => 'OAuth/OIDC Signing Key', 'status' => 'fail', 'detail' => 'Kein aktiver Signing Key vorhanden.'];
        }

        $ageDays = $active->rotated_at?->diffInDays(now()) ?? 0;
        $warning = collect(ExpiryWarningService::warnings())->firstWhere('label', 'OIDC-Signing-Key sollte rotiert werden');

        if ($warning) {
            return ['label' => 'OAuth/OIDC Signing Key', 'status' => 'warn', 'detail' => $warning['detail']];
        }

        return ['label' => 'OAuth/OIDC Signing Key', 'status' => 'ok', 'detail' => "kid: {$active->kid}, Alter: {$ageDays} Tage"];
    }

    private function checkSamlCertificate(): array
    {
        $cert = SamlCertificate::where('type', 'signing')->where('is_active', true)->orderByDesc('created_at')->first();

        if (! $cert) {
            return ['label' => 'SAML Signing-Zertifikat', 'status' => 'fail', 'detail' => 'Kein aktives Zertifikat vorhanden.'];
        }

        if (! $cert->expires_at) {
            return ['label' => 'SAML Signing-Zertifikat', 'status' => 'warn', 'detail' => 'Kein Ablaufdatum bekannt.'];
        }

        $warning = collect(ExpiryWarningService::warnings())->first(fn ($w) => str_starts_with($w['label'], 'SAML-Zertifikat'));

        if ($warning) {
            return ['label' => 'SAML Signing-Zertifikat', 'status' => $warning['level'], 'detail' => $warning['detail']];
        }

        return ['label' => 'SAML Signing-Zertifikat', 'status' => 'ok', 'detail' => "Gültig bis {$cert->expires_at->toDateString()}."];
    }
}
