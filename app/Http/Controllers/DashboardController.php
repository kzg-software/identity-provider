<?php

namespace App\Http\Controllers;

use App\Directory\DirectoryTestService;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Directory;
use App\Models\OauthClient;
use App\Models\SamlServiceProvider;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AccessPolicyEvaluator;
use App\Services\ExpiryWarningService;
use App\Services\UpdateChecker;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        if (! $user->is_admin) {
            $applications = $this->applicationsAccessibleBy($user);

            return view('dashboard-user', [
                'applications' => $applications,
                'categorizedApplications' => $applications->filter(fn (Application $a) => filled($a->category))
                    ->groupBy('category')
                    ->sortKeys(),
                'uncategorizedApplications' => $applications->filter(fn (Application $a) => blank($a->category))->values(),
            ]);
        }

        $kpis = [
            'users_active' => User::where('is_active', true)->count(),
            'sessions_active' => UserSession::active()->count(),
            'failed_logins_24h' => AuditLog::where('event', 'login.failed')->where('created_at', '>=', now()->subDay())->count(),
            'applications' => Application::where('is_active', true)->count(),
        ];

        $stats = [
            'users_total' => User::count(),
            'users_local' => User::where('auth_source', 'local')->count(),
            'users_ad' => User::where('auth_source', 'active_directory')->count(),
            'oauth_clients' => OauthClient::count(),
            'saml_providers' => SamlServiceProvider::count(),
            'directories_connected' => Directory::where('is_active', true)->count(),
        ];

        $recentLogins = AuditLog::whereIn('event', ['login.success', 'login.failed'])
            ->latest('created_at')
            ->limit(8)
            ->get();

        // Update-Prüfung im Hintergrund auffrischen, wenn das letzte Ergebnis
        // veraltet ist (läuft nach dem Ausliefern der Antwort, kein Queue-Worker
        // nötig). Der Scheduler-Job "updates:check" bleibt der Regelfall.
        if (UpdateChecker::enabled() && UpdateChecker::isStale()) {
            dispatch(fn () => UpdateChecker::refresh())->afterResponse();
        }

        $warnings = ExpiryWarningService::warnings();

        foreach (Directory::where('is_active', true)->get() as $directory) {
            $result = $this->testDirectory($directory);

            if (! $result['ok']) {
                $warnings[] = [
                    'label' => "AD/LDAP nicht erreichbar: {$directory->name}",
                    'detail' => $result['message'],
                    'level' => 'fail',
                    'url' => route('admin.directories.show', $directory),
                ];
            }
        }

        return view('dashboard', compact('kpis', 'stats', 'recentLogins', 'warnings'));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testDirectory(Directory $directory): array
    {
        try {
            return (new DirectoryTestService)->testConnection($directory);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Application>
     */
    private function applicationsAccessibleBy(User $user)
    {
        return Application::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Application $application) => AccessPolicyEvaluator::mayAccess($application, $user))
            ->values();
    }
}
