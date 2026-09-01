<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_admin || ! $user->is_active) {
            abort(403, 'Nur Administrator-Konten dürfen auf die Systemadministration zugreifen.');
        }

        return $next($request);
    }
}
