<?php

namespace App\Http\Controllers\Saml;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Saml\SamlIdpService;
use Illuminate\Http\Response;

class MetadataController extends Controller
{
    public function __construct(private readonly SamlIdpService $saml)
    {
    }

    /**
     * GET /saml/metadata — global IdP metadata.
     */
    public function global(): Response
    {
        return $this->xmlResponse($this->saml->idpMetadataXml());
    }

    /**
     * GET /saml/{application}/metadata — IdP metadata tailored to one SP's NameID format.
     */
    public function forApplication(Application $application): Response
    {
        $sp = $application->samlServiceProviders()->first();

        return $this->xmlResponse($this->saml->idpMetadataXml($sp));
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'application/samlmetadata+xml');
    }
}
