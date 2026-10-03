<?php

namespace App\Http\Controllers\Saml;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SamlServiceProvider;
use App\Saml\SamlIdpService;
use App\Services\SingleLogoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SloController extends Controller
{
    public function __construct(private readonly SamlIdpService $saml, private readonly SingleLogoutService $singleLogout) {}

    /**
     * GET/POST /saml/slo — SP-initiated Single Logout: ends the local IdP
     * session and returns a signed LogoutResponse to the requesting SP.
     */
    public function handle(Request $request): View
    {
        $raw = $request->query('SAMLRequest') ?? $request->input('SAMLRequest');

        if (! $raw) {
            abort(400, 'SAMLRequest fehlt.');
        }

        try {
            $xml = $request->isMethod('get')
                ? $this->saml->inflateRedirectMessage($raw)
                : $this->saml->decodePostMessage($raw);

            $parsed = $this->saml->parseLogoutRequest($xml);
        } catch (\Throwable $e) {
            abort(400, 'Ungültige LogoutRequest: '.$e->getMessage());
        }

        $sp = SamlServiceProvider::query()->where('entity_id', $parsed['issuer'])->first();

        // Verlangt der Service Provider signierte Anfragen, wird auch die
        // LogoutRequest geprueft. So kann niemand ueber einen gefaelschten
        // Link fremde Sitzungen beenden.
        if ($sp && $sp->require_signed_requests) {
            $signatureOk = $request->isMethod('post') && $this->saml->verifyRequestSignature($xml, $sp);

            if (! $signatureOk) {
                AuditLog::record('saml.slo.invalid_signature', Auth::user(), ['sp' => $sp->entity_id], $sp->application);
                abort(400, 'LogoutRequest-Signatur konnte nicht verifiziert werden.');
            }
        }

        if (($parsed['id'] ?? '') === '') {
            abort(400, 'LogoutRequest ohne ID.');
        }

        try {
            $this->saml->assertNotReplayed($parsed['id'], 'LogoutRequest');
        } catch (\Throwable $e) {
            abort(400, $e->getMessage());
        }

        $user = Auth::user();
        AuditLog::record('saml.slo.request', $user, ['sp' => $sp?->entity_id], $sp?->application);

        $samlLogouts = $user
            ? $this->singleLogout->terminate($user, $request->session()->getId(), exceptSamlSpId: $sp?->id)
            : [];

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $destination = $sp?->slo_url ?: url('/login');
        $logoutResponse = $this->saml->buildLogoutResponse($parsed['id'], $destination);

        $relayState = $request->query('RelayState') ?? $request->input('RelayState');

        if ($samlLogouts !== []) {
            return $this->singleLogout->chainView($samlLogouts, null, [
                'action' => $destination,
                'fields' => array_filter(['SAMLResponse' => $logoutResponse, 'RelayState' => $relayState], fn ($v) => ! empty($v)),
            ]);
        }

        return view('saml.auto_submit', [
            'acsUrl' => $destination,
            'samlResponse' => $logoutResponse,
            'relayState' => $relayState,
            'paramName' => 'SAMLResponse',
        ]);
    }
}
