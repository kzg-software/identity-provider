<?php

namespace App\Http\Middleware;

use App\Directory\DirectoryConnectionResolver;
use App\Directory\DirectoryResolver;
use App\Directory\DirectorySyncService;
use App\Directory\GroupMembershipFilter;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Integrated Windows Authentication (Kerberos/SPNEGO).
 *
 * This middleware does NOT perform the Kerberos handshake itself — that is
 * done by the webserver (IIS Windows Authentication, Apache mod_auth_gssapi,
 * or an Nginx SPNEGO module), which validates the client's Kerberos ticket
 * and, on success, passes the authenticated Windows identity through as the
 * REMOTE_USER (or AUTH_USER) server variable. See the "Windows SSO" help
 * page in the installer (Schritt 6) for the required server configuration
 * (SPN, Keytab, HTTP Principal).
 *
 * If no REMOTE_USER is present, this middleware does nothing and the normal
 * login page is shown — there is no fallback/fake login.
 */
class WindowsSsoAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        // REMOTE_USER/AUTH_USER: Apache mod_auth_gssapi, Nginx SPNEGO.
        // PHP_AUTH_USER: IIS mit aktivierter Windows Authentication (ohne
        // anonymen Zugriff) reicht den bereits per Kerberos/NTLM validierten
        // Benutzer bei PHP über FastCGI häufig so statt als REMOTE_USER durch.
        // In allen Fällen hat der Webserver die Identität bereits geprüft;
        // ohne einen dieser Header findet kein automatischer Login statt.
        $remoteUser = $request->server('REMOTE_USER')
            ?? $request->server('AUTH_USER')
            ?? $request->server('REDIRECT_REMOTE_USER')
            ?? $request->server('PHP_AUTH_USER');

        if (! $remoteUser) {
            return $next($request);
        }

        // In den Systemeinstellungen abschaltbar: dann erscheint für alle die
        // normale Anmeldeseite, auch wenn der Webserver eine Identität liefert.
        if (! SystemSetting::windowsSsoEnabled()) {
            return $next($request);
        }

        try {
            $this->authenticateFromRemoteUser($request, (string) $remoteUser);
        } catch (Throwable $e) {
            // Windows-SSO darf nie die App zum Absturz bringen; fällt auf normalen Login zurück.
            Log::warning('Windows-SSO-Login fehlgeschlagen', ['remote_user' => $remoteUser, 'error' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function authenticateFromRemoteUser(Request $request, string $remoteUser): void
    {
        [$netbiosOrUpnSuffix, $samAccountName] = DirectoryResolver::parseDomainQualifiedUsername($remoteUser);

        $directory = DirectoryResolver::resolveSingle($netbiosOrUpnSuffix);

        if (! $directory || ! $directory->is_active) {
            return;
        }

        DirectoryConnectionResolver::connect($directory);
        $connectionName = DirectoryConnectionResolver::connectionName($directory);

        $ldapUser = LdapUser::on($connectionName)
            ->in($directory->userSearchDn() ?? DirectoryConnectionResolver::resolveBaseDn($directory, $connectionName))
            ->where('samaccountname', '=', $samAccountName)
            ->first();

        if (! $ldapUser) {
            return;
        }

        // Verzeichnis auf Mitglieder bestimmter Gruppen beschränkt?
        if (! GroupMembershipFilter::allows($directory, $connectionName, $ldapUser)) {
            return;
        }

        $user = (new DirectorySyncService)->syncSingleUser($directory, $connectionName, $ldapUser);

        if (! $user || ! $user->is_active) {
            return;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_method' => 'windows_sso',
        ])->save();

        $request->session()->regenerate();
        Auth::guard('web')->login($user);

        AuditLog::record('login.windows_sso', $user, ['remote_user' => $remoteUser]);

        app(SessionTracker::class)->record($user, $request, 'windows_sso');
    }
}
