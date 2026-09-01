<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OidcKey;
use App\Oidc\OidcKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OidcKeyController extends Controller
{
    public function __construct(private readonly OidcKeyService $keys)
    {
    }

    public function index(): View
    {
        $keys = $this->keys->publishableKeys()->map(function (OidcKey $key) {
            $details = openssl_pkey_get_details(openssl_pkey_get_public($key->public_key));
            $expiresAt = $key->created_at->copy()->addYear();

            return [
                'model' => $key,
                'fingerprint' => $this->keys->fingerprint($key),
                'bits' => $details['bits'] ?? null,
                'expires_at' => $expiresAt,
                'expiring_soon' => $expiresAt->diffInDays(now(), false) > -30,
            ];
        });

        return view('admin.oidc-keys.index', compact('keys'));
    }

    public function rotate(Request $request): RedirectResponse
    {
        $key = $this->keys->rotate();

        AuditLog::record('oidc.key_rotated', $request->user(), ['kid' => $key->kid]);

        return back()->with('status', 'Neuer Signaturschlüssel wurde erzeugt und aktiviert.');
    }
}
