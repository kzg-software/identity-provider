<?php

namespace App\Http\Controllers\Saml;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SamlServiceProvider;
use App\Saml\SamlIdpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SloController extends Controller
{
    public function __construct(private readonly SamlIdpService $saml)
    {
    }

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

        $user = Auth::user();
        AuditLog::record('saml.slo.request', $user, ['sp' => $sp?->entity_id], $sp?->application);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $destination = $sp?->slo_url ?: url('/login');
        $logoutResponse = $this->saml->buildLogoutResponse($parsed['id'], $destination);

        return view('saml.auto_submit', [
            'acsUrl' => $destination,
            'samlResponse' => $logoutResponse,
            'relayState' => $request->query('RelayState') ?? $request->input('RelayState'),
            'paramName' => 'SAMLResponse',
        ]);
    }
}
