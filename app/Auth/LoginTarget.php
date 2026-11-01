<?php

namespace App\Auth;

use App\Models\Application;
use App\Models\OauthClient;
use App\Models\SamlServiceProvider;
use Illuminate\Http\Request;

/**
 * Ermittelt, zu welcher Anwendung eine Anmeldung gerade unterwegs ist, damit
 * die Anmeldeseite „Weiter zu …“ anzeigen kann. Quelle ist derselbe
 * Session-Zustand, den LoginCompletion nach dem Login auswertet: eine
 * ausstehende SAML-Anfrage oder ein gemerktes /oauth/authorize-Ziel.
 */
class LoginTarget
{
    public static function application(Request $request): ?Application
    {
        $session = $request->session();

        if ($pending = $session->get('saml.pending')) {
            $application = SamlServiceProvider::query()->find($pending['sp_id'] ?? null)?->application;
        } else {
            $application = self::fromIntendedUrl((string) $session->get('url.intended', ''));
        }

        return $application?->is_active ? $application : null;
    }

    private static function fromIntendedUrl(string $intended): ?Application
    {
        if ($intended === '') {
            return null;
        }

        $authorizePath = parse_url(route('oauth.authorize'), PHP_URL_PATH);

        if (parse_url($intended, PHP_URL_PATH) !== $authorizePath) {
            return null;
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);

        $clientId = $query['client_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        return OauthClient::query()->where('client_id', $clientId)->first()?->application;
    }
}
