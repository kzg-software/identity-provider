<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SamlCertificate;
use App\Saml\SamlCertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SamlCertificateController extends Controller
{
    public function __construct(private readonly SamlCertificateService $certificates)
    {
    }

    public function index(): View
    {
        $certificates = $this->certificates->publishable()->map(function (SamlCertificate $cert) {
            return [
                'model' => $cert,
                'expiring_soon' => $cert->expires_at?->diffInDays(now(), false) > -30,
            ];
        });

        return view('admin.saml-certificates.index', compact('certificates'));
    }

    public function rotate(Request $request): RedirectResponse
    {
        $cert = $this->certificates->rotate();

        AuditLog::record('saml.certificate_rotated', $request->user(), ['fingerprint' => $cert->fingerprint]);

        return back()->with('status', 'Neues SAML-Signaturzertifikat wurde erzeugt und aktiviert.');
    }
}
