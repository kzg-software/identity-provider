<?php

use App\Http\Middleware\EnsureNotInMaintenance;
use App\Http\Middleware\EnsureSystemIsInstalled;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\TouchUserSession;
use App\Http\Middleware\ValidateOAuthAccessToken;
use App\Http\Middleware\WindowsSsoAuthenticate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hinter einem Reverse Proxy / Load Balancer (Docker, Traefik, nginx,
        // IIS-ARR, …) muss die App den X-Forwarded-*-Headern des Proxys
        // vertrauen, damit URL-Erzeugung, HTTPS-Erkennung und Client-IP
        // stimmen. Opt-in über die Umgebungsvariable TRUSTED_PROXIES:
        //   TRUSTED_PROXIES=*                 -> allen vertrauen (nur wenn der
        //                                        Proxy die einzige Route ist)
        //   TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12  -> konkrete Netze/IPs
        $trustedProxies = env('TRUSTED_PROXIES');
        if (! empty($trustedProxies)) {
            $middleware->trustProxies(
                at: $trustedProxies === '*'
                    ? '*'
                    : array_map('trim', explode(',', $trustedProxies)),
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_PREFIX
                    | Request::HEADER_X_FORWARDED_AWS_ELB,
            );
        }

        $middleware->prependToGroup('web', [
            EnsureSystemIsInstalled::class,
        ]);

        $middleware->appendToGroup('web', [
            WindowsSsoAuthenticate::class,
            EnsureNotInMaintenance::class,
            TouchUserSession::class,
        ]);

        $middleware->alias([
            'admin' => RequireAdmin::class,
            'oauth_token' => ValidateOAuthAccessToken::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'oauth/token',
            'oauth/revoke',
            'saml/sso',
            'saml/slo',
        ]);

        // Laravel sorts framework middleware (incl. the "auth" alias) by a
        // fixed priority list, which by default would run the auth check
        // BEFORE an appended group middleware like WindowsSsoAuthenticate on
        // any auth-protected route - breaking automatic SSO login there.
        // Insert WindowsSsoAuthenticate directly before Authenticate without
        // disturbing the rest of Laravel's default priority ordering.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: WindowsSsoAuthenticate::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
