<?php

namespace App\Http\Controllers;

use App\Directory\DirectoryTestService;
use App\Models\AuditLog;
use App\Models\Directory;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreService;
use App\Support\UploadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InstallController extends Controller
{
    /** Startseite: neu einrichten oder aus einer Sicherung wiederherstellen. */
    public function welcome(): View
    {
        return view('install.welcome');
    }

    /** Formular zum Wiederherstellen aus einer Sicherungsdatei. */
    public function restore(): View
    {
        return view('install.restore');
    }

    public function restoreStore(Request $request): RedirectResponse
    {
        UploadLimits::guard($request, 'backup');

        $request->validate([
            'backup' => ['required', 'file', 'max:1048576'],
            'password' => ['required', 'string'],
        ], [
            'backup.required' => 'Bitte die Sicherungsdatei auswählen.',
            'backup.max' => 'Die Sicherungsdatei ist größer als 1 GB und kann so nicht hochgeladen werden.',
            'password.required' => 'Bitte das Passwort der Sicherungsdatei eingeben.',
        ]);

        @set_time_limit(0);

        try {
            $manifest = app(RestoreService::class)->restore(
                $request->file('backup')->getRealPath(),
                $request->string('password')->value(),
            );
        } catch (BackupException $e) {
            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }

        AuditLog::record('system.restored_from_backup', null, [
            'created_at' => $manifest['created_at'] ?? null,
            'app_version' => $manifest['app_version'] ?? null,
        ]);

        return redirect()->route('login')->with('status',
            'Die Sicherung wurde eingespielt. Bitte melden Sie sich mit den Zugangsdaten aus der Sicherung an.');
    }

    /** Schritt 1: Systemprüfung */
    public function requirements(): View
    {
        return view('install.requirements', ['checks' => $this->runRequirementChecks()]);
    }

    private function runRequirementChecks(): array
    {
        $extensions = ['openssl', 'ldap', 'xml', 'curl', 'mbstring', 'intl', 'session', 'sodium'];

        $checks = [
            [
                'label' => 'PHP-Version >= 8.3',
                'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'detail' => PHP_VERSION,
            ],
        ];

        foreach ($extensions as $ext) {
            $checks[] = [
                'label' => "PHP-Extension: {$ext}",
                'ok' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'geladen' : 'fehlt',
            ];
        }

        $checks[] = [
            'label' => 'Schreibrechte storage/',
            'ok' => is_writable(storage_path()),
            'detail' => storage_path(),
        ];

        $checks[] = [
            'label' => 'Schreibrechte bootstrap/cache/',
            'ok' => is_writable(base_path('bootstrap/cache')),
            'detail' => base_path('bootstrap/cache'),
        ];

        return $checks;
    }

    public function requirementsContinue(): RedirectResponse
    {
        $checks = $this->runRequirementChecks();

        if (collect($checks)->contains(fn ($c) => ! $c['ok'])) {
            return back()->withErrors(['requirements' => 'Nicht alle Systemvoraussetzungen sind erfüllt.']);
        }

        return redirect()->route('install.database');
    }

    /** Schritt 2: Datenbank */
    public function database(): View
    {
        return view('install.database');
    }

    public function databaseTest(Request $request): RedirectResponse
    {
        $data = $this->validateDatabase($request);

        try {
            $this->testConnection($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['connection' => 'Verbindung fehlgeschlagen: '.$e->getMessage()]);
        }

        return back()->withInput()->with('status', 'Verbindung erfolgreich.');
    }

    public function databaseStore(Request $request): RedirectResponse
    {
        $data = $this->validateDatabase($request);

        try {
            $this->testConnection($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['connection' => 'Verbindung fehlgeschlagen: '.$e->getMessage()]);
        }

        $this->writeEnvDatabase($data);

        Artisan::call('config:clear');
        Artisan::call('migrate', ['--force' => true]);

        return redirect()->route('install.system');
    }

    private function validateDatabase(Request $request): array
    {
        return $request->validate([
            'connection' => 'required|in:sqlite,mysql,mariadb,pgsql',
            'host' => 'nullable|string',
            'port' => 'nullable|numeric',
            'database' => 'required|string',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ]);
    }

    private function testConnection(array $data): void
    {
        if ($data['connection'] === 'sqlite') {
            $path = database_path($data['database']);
            if (! file_exists($path)) {
                touch($path);
            }

            config(['database.connections.install_test' => [
                'driver' => 'sqlite',
                'database' => $path,
            ]]);
        } else {
            config(['database.connections.install_test' => [
                'driver' => $data['connection'] === 'mariadb' ? 'mysql' : $data['connection'],
                'host' => $data['host'],
                'port' => $data['port'] ?? 3306,
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'],
            ]]);
        }

        DB::connection('install_test')->getPdo();
        DB::purge('install_test');
    }

    private function writeEnvDatabase(array $data): void
    {
        $env = [
            'DB_CONNECTION' => $data['connection'] === 'mariadb' ? 'mysql' : $data['connection'],
        ];

        if ($data['connection'] === 'sqlite') {
            $env['DB_DATABASE'] = database_path($data['database']);
        } else {
            $env['DB_HOST'] = $data['host'];
            $env['DB_PORT'] = $data['port'] ?? 3306;
            $env['DB_DATABASE'] = $data['database'];
            $env['DB_USERNAME'] = $data['username'];
            $env['DB_PASSWORD'] = $data['password'];
        }

        $this->updateEnvFile($env);
    }

    private function updateEnvFile(array $values): void
    {
        // In der Testumgebung nicht die echte .env des Projekts anfassen.
        // Die Installer-Tests prüfen nur Weiterleitungen und die Datenbank.
        if (app()->environment('testing')) {
            return;
        }

        $path = base_path('.env');
        $content = file_exists($path) ? file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            // Zeilenumbrueche wuerden zusaetzliche .env-Zeilen einschleusen.
            $value = str_replace(["\r", "\n"], '', (string) $value);
            $escaped = (str_contains($value, ' ') || str_contains($value, '"'))
                ? '"'.addcslashes($value, '"\\').'"'
                : $value;
            $pattern = "/^{$key}=.*/m";

            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, fn () => "{$key}={$escaped}", $content);
            } else {
                $content .= "\n{$key}={$escaped}";
            }
        }

        file_put_contents($path, $content);
    }

    /** Schritt 3: System */
    public function system(): View
    {
        return view('install.system');
    }

    public function systemStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'system_name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'timezone' => 'required|timezone',
            'locale' => 'required|string',
            'session_lifetime' => 'required|integer|min:5',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,gif,webp|max:2048',
            'favicon' => 'nullable|file|mimes:png,jpg,jpeg,gif,webp,ico,bmp|max:1024',
            'mail_host' => 'nullable|string',
            'mail_port' => 'nullable|numeric',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_from_address' => 'nullable|email',
        ]);

        SystemSetting::set('system_name', $data['system_name']);
        SystemSetting::set('base_url', $data['base_url']);
        SystemSetting::set('timezone', $data['timezone']);
        SystemSetting::set('locale', $data['locale']);
        SystemSetting::set('session_lifetime', (string) $data['session_lifetime']);

        if ($request->hasFile('logo')) {
            SystemSetting::set('logo_path', $request->file('logo')->store('branding', 'public'));
        }

        if ($request->hasFile('favicon')) {
            SystemSetting::set('favicon_path', $request->file('favicon')->store('branding', 'public'));
        }

        foreach (['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_from_address'] as $key) {
            if (! empty($data[$key])) {
                SystemSetting::set($key, (string) $data[$key]);
            }
        }

        $this->updateEnvFile([
            'APP_NAME' => str_replace(' ', '_', $data['system_name']),
            'APP_URL' => $data['base_url'],
            'APP_TIMEZONE' => $data['timezone'],
            'APP_LOCALE' => $data['locale'],
        ]);

        return redirect()->route('install.admin');
    }

    /** Schritt 4: Lokaler Administrator */
    public function admin(): View
    {
        return view('install.admin');
    }

    public function adminStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users,username',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        User::create([
            'username' => $data['username'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'auth_source' => 'local',
            'manual_roles' => ['admin'],
            'is_active' => true,
        ]);

        return redirect()->route('install.directory');
    }

    /** Schritt 5: Active Directory (optional) */
    public function directory(): View
    {
        return view('install.directory');
    }

    private function validateDirectory(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string',
            'realm' => 'nullable|string',
            'domain_controller' => 'nullable|string',
            'ldap_server' => 'nullable|string',
            'ldap_port' => 'nullable|numeric',
            'use_ldaps' => 'nullable|boolean',
            'base_dn' => 'nullable|string',
            'user_dn' => 'nullable|string',
            'group_dn' => 'nullable|string',
            'login_group_filter' => 'nullable|string|max:4000',
            'bind_user' => 'nullable|string',
            'bind_password' => 'nullable|string',
            'upn_suffix' => 'nullable|string',
            'netbios_domain' => 'nullable|string',
            'kerberos_realm' => 'nullable|string',
        ]);
    }

    private function makeTransientDirectory(array $data, Request $request): Directory
    {
        return new Directory([
            'name' => $data['name'],
            'type' => 'active_directory',
            'domain' => $data['domain'] ?? null,
            'realm' => $data['realm'] ?? null,
            'netbios_domain' => $data['netbios_domain'] ?? null,
            'domain_controller' => $data['domain_controller'] ?? null,
            'ldap_server' => $data['ldap_server'] ?? null,
            'ldap_port' => $data['ldap_port'] ?? null,
            'use_ldaps' => $request->boolean('use_ldaps'),
            'base_dn' => $data['base_dn'] ?? null,
            'user_dn' => $data['user_dn'] ?? null,
            'group_dn' => $data['group_dn'] ?? null,
            'login_group_filter' => $data['login_group_filter'] ?? null,
            'bind_user' => $data['bind_user'] ?? null,
            'bind_password_encrypted' => $data['bind_password'] ?? null,
            'upn_suffix' => $data['upn_suffix'] ?? null,
            'kerberos_realm' => $data['kerberos_realm'] ?? null,
        ]);
    }

    public function directoryTest(Request $request): RedirectResponse
    {
        $data = $this->validateDirectory($request);
        $directory = $this->makeTransientDirectory($data, $request);
        $directory->id = 0; // transiente Verbindung, wird nicht persistiert

        $result = (new DirectoryTestService)->testConnection($directory);

        if ($result['ok']) {
            return back()->withInput()->with('status', $result['message']);
        }

        return back()->withInput()->withErrors(['connection' => $result['message']]);
    }

    public function directoryStore(Request $request): RedirectResponse
    {
        if ($request->boolean('skip')) {
            return redirect()->route('install.windows-sso');
        }

        $data = $this->validateDirectory($request);

        Directory::create([
            'name' => $data['name'],
            'type' => 'active_directory',
            'domain' => $data['domain'] ?? null,
            'realm' => $data['realm'] ?? null,
            'netbios_domain' => $data['netbios_domain'] ?? null,
            'domain_controller' => $data['domain_controller'] ?? null,
            'ldap_server' => $data['ldap_server'] ?? null,
            'ldap_port' => $data['ldap_port'] ?? null,
            'use_ldaps' => $request->boolean('use_ldaps'),
            'base_dn' => $data['base_dn'] ?? null,
            'user_dn' => $data['user_dn'] ?? null,
            'group_dn' => $data['group_dn'] ?? null,
            'login_group_filter' => $data['login_group_filter'] ?? null,
            'bind_user' => $data['bind_user'] ?? null,
            'bind_password_encrypted' => $data['bind_password'] ?? null,
            'upn_suffix' => $data['upn_suffix'] ?? null,
            'kerberos_realm' => $data['kerberos_realm'] ?? null,
            'is_active' => false,
        ]);

        return redirect()->route('install.windows-sso');
    }

    /** Schritt 6: Windows SSO Hilfestellung (informativ — Kerberos/SPNEGO-Middleware folgt in Phase 2) */
    public function windowsSso(): View
    {
        return view('install.windows-sso');
    }

    public function windowsSsoStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'spn' => 'nullable|string',
            'realm' => 'nullable|string',
            'hostname' => 'nullable|string',
            'http_principal' => 'nullable|string',
        ]);

        SystemSetting::set('windows_sso_config', json_encode($data));

        return redirect()->route('install.finish');
    }

    /** Schritt 7: Abschluss */
    public function finish(): View
    {
        return view('install.finish');
    }

    public function complete(): RedirectResponse
    {
        if (! User::where('auth_source', 'local')->where('is_admin', true)->exists()) {
            return redirect()->route('install.admin')
                ->withErrors(['admin' => 'Es muss zuerst ein lokaler Administrator angelegt werden, bevor die Installation abgeschlossen werden kann.']);
        }

        SystemSetting::set('installed', '1');
        SystemSetting::set('installed_at', now()->toIso8601String());

        AuditLog::record('system.installed');

        return redirect()->route('login')->with('status', 'Installation abgeschlossen. Bitte melden Sie sich an.');
    }
}
