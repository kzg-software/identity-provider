<?php

namespace App\Http\Controllers\Saml;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SamlServiceProvider;
use App\Saml\SamlIdpService;
use App\Services\AccessPolicyEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SsoController extends Controller
{
    public function __construct(private readonly SamlIdpService $saml)
    {
    }

    /**
     * GET/POST /saml/sso — receives an AuthnRequest (HTTP-Redirect or HTTP-POST binding).
     */
    public function handle(Request $request): RedirectResponse|View
    {
        $raw = $request->query('SAMLRequest') ?? $request->input('SAMLRequest');

        if (! $raw) {
            abort(400, 'SAMLRequest fehlt.');
        }

        try {
            $xml = $request->isMethod('get')
                ? $this->saml->inflateRedirectMessage($raw)
                : $this->saml->decodePostMessage($raw);

            $parsed = $this->saml->parseAuthnRequest($xml);
        } catch (\Throwable $e) {
            abort(400, 'Ungültige SAML-Anfrage: '.$e->getMessage());
        }

        $sp = SamlServiceProvider::query()
            ->where('entity_id', $parsed['issuer'])
            ->where('is_active', true)
            ->first();

        if (! $sp) {
            abort(404, 'Unbekannter oder inaktiver Service Provider.');
        }

        if ($sp->require_signed_requests) {
            $signatureOk = $request->isMethod('post')
                ? $this->saml->verifyRequestSignature($xml, $sp)
                : $this->verifyRedirectSignature($request, $sp);

            if (! $signatureOk) {
                AuditLog::record('saml.authn_request.invalid_signature', null, ['sp' => $sp->entity_id], $sp->application);
                abort(400, 'AuthnRequest-Signatur konnte nicht verifiziert werden.');
            }
        }

        try {
            $this->saml->assertNotReplayed($parsed['id'], 'AuthnRequest');
        } catch (\Throwable $e) {
            abort(400, $e->getMessage());
        }

        $acsUrl = $parsed['acs_url'] ?: $sp->acs_url;
        $relayState = $request->query('RelayState') ?? $request->input('RelayState');

        if (! Auth::check()) {
            $request->session()->put('saml.pending', [
                'sp_id' => $sp->id,
                'request_id' => $parsed['id'],
                'acs_url' => $acsUrl,
                'relay_state' => $relayState,
            ]);

            return redirect()->route('login');
        }

        return $this->completeSso($sp, $parsed['id'], $acsUrl, $relayState);
    }

    /**
     * Called after a successful login when a SAML AuthnRequest was pending
     * (see LoginController, which redirects back here via session state).
     */
    public function resume(Request $request): RedirectResponse|View
    {
        $pending = $request->session()->pull('saml.pending');

        if (! $pending) {
            abort(400, 'Keine ausstehende SAML-Anfrage vorhanden.');
        }

        $sp = SamlServiceProvider::query()->findOrFail($pending['sp_id']);

        return $this->completeSso($sp, $pending['request_id'], $pending['acs_url'], $pending['relay_state']);
    }

    private function completeSso(SamlServiceProvider $sp, string $requestId, string $acsUrl, ?string $relayState): View|RedirectResponse
    {
        $user = Auth::user();

        if ($sp->application && \App\Support\MaintenanceGate::applicationBlockedFor($sp->application, $user)) {
            AuditLog::record('saml.sso.maintenance', $user, ['sp' => $sp->entity_id], $sp->application);
            abort(503, \App\Support\MaintenanceGate::applicationMessage($sp->application));
        }

        if (! $this->userMayAccess($sp, $user)) {
            AuditLog::record('saml.sso.denied', $user, ['sp' => $sp->entity_id], $sp->application);
            abort(403, 'Sie sind nicht berechtigt, diese Anwendung zu nutzen.');
        }

        $attributes = $this->saml->mapAttributes($sp, $user);
        $samlResponse = $this->saml->buildSignedResponse($sp, $user, $acsUrl, $requestId, $attributes);

        AuditLog::record('saml.sso.success', $user, ['sp' => $sp->entity_id], $sp->application);

        return view('saml.auto_submit', [
            'acsUrl' => $acsUrl,
            'samlResponse' => $samlResponse,
            'relayState' => $relayState,
        ]);
    }

    private function verifyRedirectSignature(Request $request, SamlServiceProvider $sp): bool
    {
        // HTTP-Redirect binding signs the query string separately (SigAlg/Signature
        // params), not the XML itself. We require SP-signed requests to use the
        // POST binding, which we can verify directly against the XML.
        return false;
    }

    private function userMayAccess(SamlServiceProvider $sp, $user): bool
    {
        return AccessPolicyEvaluator::mayAccessApplication($sp->application_id, $user);
    }
}
