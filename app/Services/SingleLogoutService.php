<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\SamlServiceProvider;
use App\Models\SsoSession;
use App\Models\User;
use App\Oidc\IdTokenService;
use App\Saml\SamlIdpService;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Single Logout: beendet beim Abmelden auch die Sitzungen in den Anwendungen,
 * bei denen sich der Benutzer über diese Portal-Sitzung angemeldet hat.
 *
 * OIDC-Clients werden per Back-Channel (Logout Token, Server zu Server)
 * benachrichtigt. SAML-Service-Provider bekommen eine LogoutRequest über den
 * Browser (Front-Channel), die Aufrufer als URLs erhalten.
 */
class SingleLogoutService
{
    public function __construct(
        private readonly SamlIdpService $saml,
        private readonly IdTokenService $idTokens,
    ) {}

    public function recordSaml(User $user, string $sessionId, SamlServiceProvider $sp, string $nameId, string $sessionIndex): void
    {
        SsoSession::query()->updateOrCreate(
            ['session_id' => $sessionId, 'protocol' => SsoSession::SAML, 'saml_service_provider_id' => $sp->id],
            ['user_id' => $user->id, 'name_id' => $nameId, 'session_index' => $sessionIndex],
        );
    }

    public function recordOidc(User $user, string $sessionId, OauthClient $client): void
    {
        SsoSession::query()->updateOrCreate(
            ['session_id' => $sessionId, 'protocol' => SsoSession::OIDC, 'oauth_client_id' => $client->id],
            ['user_id' => $user->id],
        );
    }

    /**
     * Beendet alle Anwendungs-Sitzungen der übergebenen Portal-Sitzungen.
     * Sendet Back-Channel-Logouts und liefert die SAML-Front-Channel-URLs
     * zurück. Die Anwendung, von der die Abmeldung ausging, wird übersprungen.
     *
     * @param  array<int, string>|string  $sessionIds
     * @return array<int, array{name: string, url: string}>
     */
    public function terminate(User $user, array|string $sessionIds, ?int $exceptSamlSpId = null, ?int $exceptClientId = null): array
    {
        $rows = SsoSession::query()
            ->whereIn('session_id', (array) $sessionIds)
            ->with(['samlServiceProvider.provider.application', 'oauthClient.provider.application'])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $samlRequests = [];
        $clients = [];

        foreach ($rows as $row) {
            if ($row->protocol === SsoSession::OIDC && $row->oauthClient && $row->oauth_client_id !== $exceptClientId) {
                $clients[$row->oauth_client_id] = $row->oauthClient;
            }

            if ($row->protocol === SsoSession::SAML && $row->samlServiceProvider && $row->saml_service_provider_id !== $exceptSamlSpId) {
                $sp = $row->samlServiceProvider;

                if ($sp->slo_url && $sp->is_active) {
                    $samlRequests[$sp->id] = [
                        'name' => $sp->name,
                        'url' => $this->saml->buildLogoutRequestUrl($sp, $row->name_id, $row->session_index),
                    ];
                }
            }
        }

        SsoSession::query()->whereIn('id', $rows->pluck('id'))->delete();

        foreach ($clients as $client) {
            $this->sendBackchannelLogout($user, $client);
        }

        if ($samlRequests !== []) {
            AuditLog::record('saml.slo.propagated', $user, ['service_providers' => array_column($samlRequests, 'name')]);
        }

        return array_values($samlRequests);
    }

    /**
     * Seite, die die SAML-LogoutRequests unsichtbar abschickt und danach
     * weiterleitet (per Link oder per POST-Formular).
     *
     * @param  array<int, array{name: string, url: string}>  $logoutRequests
     * @param  array{action: string, fields: array<string, string>}|null  $finalForm
     */
    public function chainView(array $logoutRequests, ?string $finalUrl, ?array $finalForm = null): View
    {
        return view('saml.slo_chain', [
            'logoutRequests' => $logoutRequests,
            'finalUrl' => $finalUrl,
            'finalForm' => $finalForm,
        ]);
    }

    private function sendBackchannelLogout(User $user, OauthClient $client): void
    {
        if (! $client->backchannel_logout_uri || ! $client->is_active) {
            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(4)
                ->connectTimeout(3)
                ->post($client->backchannel_logout_uri, ['logout_token' => $this->idTokens->issueLogoutToken($user, $client)]);

            $ok = $response->successful();
        } catch (\Throwable $e) {
            $ok = false;
        }

        AuditLog::record(
            $ok ? 'oauth.backchannel_logout.sent' : 'oauth.backchannel_logout.failed',
            $user,
            ['client' => $client->name],
            $client->application,
        );
    }
}
