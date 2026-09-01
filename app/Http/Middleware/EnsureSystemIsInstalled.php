<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemIsInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('install*') && ! SystemSetting::isInstalled()) {
            return redirect()->route('install.index');
        }

        if ($request->is('install*') && SystemSetting::isInstalled()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
