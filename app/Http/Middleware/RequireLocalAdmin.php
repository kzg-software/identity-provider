<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLocalAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->auth_source !== 'local' || ! $user->is_admin) {
            abort(403, 'Nur lokale Administrator-Konten dürfen auf die Systemadministration zugreifen.');
        }

        return $next($request);
    }
}
