<?php

namespace App\Http\Middleware;

use App\Support\MaintenanceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Systemweiter Wartungsmodus. Ist er aktiv, bekommt jeder eine 503-Wartungsseite
 * - außer den in der Freigabeliste genannten Benutzern/Gruppen und lokalen
 * Administratoren (siehe App\Support\MaintenanceGate).
 *
 * Anmeldung, Abmeldung, der SSO-Handshake, der Health-Check, der Installer und
 * die Systemeinstellungen selbst bleiben immer erreichbar, damit sich ein
 * Administrator einloggen und den Wartungsmodus wieder abschalten kann.
 */
class EnsureNotInMaintenance
{
    private const ALWAYS_ALLOWED_ROUTES = [
        'login', 'login.attempt', 'login.directory', 'logout', 'auth.negotiate',
        'admin.settings.edit', 'admin.settings.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! MaintenanceGate::systemActive()) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALWAYS_ALLOWED_ROUTES) || $request->is('up', 'install', 'install/*')) {
            return $next($request);
        }

        if (MaintenanceGate::userBypassesSystem($request->user())) {
            return $next($request);
        }

        $message = MaintenanceGate::systemMessage();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        return response()->view('maintenance', ['message' => $message], 503)
            ->header('Retry-After', '3600');
    }
}
