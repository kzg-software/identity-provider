<?php

namespace App\Http\Middleware;

use App\Services\SessionTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TouchUserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            app(SessionTracker::class)->touch($request);
        }

        return $next($request);
    }
}
