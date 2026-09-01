<?php

namespace App\Saml;

use App\Models\SamlReplayProtection;
use App\Models\SamlServiceProvider;
use App\Models\SystemSetting;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Core SAML 2.0 Identity Provider logic: parses incoming AuthnRequest/LogoutRequest
 * messages and builds signed Response/Assertion/LogoutResponse XML. All signing,
 * canonicalization and XML parsing goes through onelogin/php-saml + robrichards/xmlseclibs
 * (see XmlSecurity) — no bespoke cryptography.
 */
class SamlIdpService
{
    public function __construct(private readonly SamlCertificateService $certificates)
    {
    }

    public function entityId(): string
    {
        return SystemSetting::get('saml_entity_id') ?: url('/saml/metadata');
    }

    /**
     * Parse a redirect-binding (deflated) SAMLRequest query parameter.
     */
    public function inflateRedirectMessage(string $encoded): string
    {
        $raw = base64_decode($encoded);
        $inflated = @gzinflate($raw);

        if ($inflated === false) {
            throw new \RuntimeException('SAML-Nachricht konnte nicht dekodiert werden.');
        }

        return $inflated;
    }

    public function decodePostMessage(string $encoded): string
    {
        $decoded = base64_decode($encoded);

        if ($decoded === false) {
            throw new \RuntimeException('SAML-Nachricht konnte nicht dekodiert werden.');
        }

        return $decoded;
    }

    /**
     * @return array{id: string, issuer: string, acs_url: ?string, destination: ?string,
     *               name_id_format: ?string, xml: string}
     */
    public function parseAuthnRequest(string $xml): array
    {
        $dom = XmlSecurity::loadSafely($xml);
        $xpath = $this->xpath($dom);

        $root = $xpath->query('//samlp:AuthnRequest')->item(0);

        if (! $root) {
            throw new \RuntimeException('Keine gültige AuthnRequest gefunden.');
        }

        $id = $root->getAttribute('ID');
        $issuer = trim((string) $xpath->evaluate('string(//saml:Issuer)', $root));
        $acsUrl = $root->getAttribute('AssertionConsumerServiceURL') ?: null;
        $destination = $root->getAttribute('Destination') ?: null;

        $nameIdPolicy = $xpath->query('//samlp:NameIDPolicy')->item(0);
        $nameIdFormat = $nameIdPolicy?->getAttribute('Format') ?: null;

        if ($id === '') {
            throw new \RuntimeException('AuthnRequest ohne ID.');
        }

        return [
            'id' => $id,
            'issuer' => $issuer,
            'acs_url' => $acsUrl,
            'destination' => $destination,
            'name_id_format' => $nameIdFormat,
            'xml' => $xml,
        ];
    }

    /**
     * Replay protection: rejects a message ID that has already been processed.
     */
    public function assertNotReplayed(string $messageId, string $type = 'AuthnRequest'): void
    {
        $exists = SamlReplayProtection::query()->where('message_id', $messageId)->exists();

        if ($exists) {
            throw new \RuntimeException('Diese SAML-Anfrage wurde bereits verarbeitet (Replay erkannt).');
        }

        SamlReplayProtection::create(['message_id' => $messageId, 'type' => $type, 'seen_at' => now()]);
    }

    public function verifyRequestSignature(string $xml, SamlServiceProvider $sp): bool
    {
        if (! $sp->certificate) {
            return false;
        }

        return XmlSecurity::verify($xml, $sp->certificate);
    }

    /**
     * Build a signed <samlp:Response> containing a signed <saml:Assertion> and
     * return it base64-encoded, ready for the ACS auto-submit form.
     */
    public function buildSignedResponse(SamlServiceProvider $sp, User $user, string $acsUrl, ?string $inResponseTo, array $attributes): string
    {
        $cert = $this->certificates->activeSigningCertificate();

        $responseId = '_'.Str::uuid();
        $assertionId = '_'.Str::uuid();
        $issueInstant = now()->toIso8601ZuluString();
        $notOnOrAfter = now()->addMinutes(5)->toIso8601ZuluString();
        $sessionNotOnOrAfter = now()->addHours($sp->application?->oauthClients->first()?->access_token_lifetime ?? 8 * 3600)->toIso8601ZuluString();
        $nameId = $this->resolveNameId($sp, $user);
        $issuer = $this->entityId();

        $attributeStatements = '';
        foreach ($attributes as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            $valueXml = '';
            foreach ($values as $value) {
                $valueXml .= '<saml:AttributeValue xsi:type="xs:string">'.htmlspecialchars((string) $value, ENT_XML1).'</saml:AttributeValue>';
            }
            $attributeStatements .= '<saml:Attribute Name="'.htmlspecialchars($name, ENT_XML1).'" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic">'.$valueXml.'</saml:Attribute>';
        }

        $inResponseToAttr = $inResponseTo ? ' InResponseTo="'.htmlspecialchars($inResponseTo, ENT_XML1).'"' : '';

        $assertionXml = <<<XML
<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="{$assertionId}" Version="2.0" IssueInstant="{$issueInstant}">
  <saml:Issuer>{$issuer}</saml:Issuer>
  <saml:Subject>
    <saml:NameID Format="{$sp->name_id_format}">{$nameId}</saml:NameID>
    <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
      <saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$acsUrl}"{$inResponseToAttr}/>
    </saml:SubjectConfirmation>
  </saml:Subject>
  <saml:Conditions NotBefore="{$issueInstant}" NotOnOrAfter="{$notOnOrAfter}">
    <saml:AudienceRestriction>
      <saml:Audience>{$sp->entity_id}</saml:Audience>
    </saml:AudienceRestriction>
  </saml:Conditions>
  <saml:AuthnStatement AuthnInstant="{$issueInstant}" SessionNotOnOrAfter="{$sessionNotOnOrAfter}">
    <saml:AuthnContext>
      <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
    </saml:AuthnContext>
  </saml:AuthnStatement>
  <saml:AttributeStatement>{$attributeStatements}</saml:AttributeStatement>
</saml:Assertion>
XML;

        if ($sp->sign_assertions) {
            $assertionDom = XmlSecurity::loadSafely($assertionXml);
            $signedAssertionXml = XmlSecurity::sign($assertionDom, $cert->private_key_encrypted, $cert->certificate);
            $signedAssertionXml = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $signedAssertionXml);
        } else {
            $signedAssertionXml = $assertionXml;
        }

        $responseXml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$acsUrl}"{$inResponseToAttr}>
  <saml:Issuer>{$issuer}</saml:Issuer>
  <samlp:Status>
    <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
  </samlp:Status>
  {$signedAssertionXml}
</samlp:Response>
XML;

        if ($sp->sign_responses) {
            $responseDom = XmlSecurity::loadSafely($responseXml);
            $responseXml = XmlSecurity::sign($responseDom, $cert->private_key_encrypted, $cert->certificate);
        }

        return base64_encode($responseXml);
    }

    public function resolveNameId(SamlServiceProvider $sp, User $user): string
    {
        return match ($sp->name_id_format) {
            'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress' => $user->email,
            'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => 'transient-'.Str::uuid(),
            'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified' => $user->username,
            default => 'idp-'.$sp->id.'-'.$user->id, // persistent
        };
    }

    /**
     * Map a user's attributes onto SAML attribute names per the SP's configured mapping.
     */
    public function mapAttributes(SamlServiceProvider $sp, User $user): array
    {
        $mappings = $sp->attributeMappings()->get();
        $attributes = [];

        foreach ($mappings as $mapping) {
            $attributes[$mapping->saml_attribute] = $mapping->user_attribute === 'groups'
                ? $user->groupNames()
                : (string) ($user->{$mapping->user_attribute} ?? '');
        }

        return $attributes;
    }

    public function idpMetadataXml(?SamlServiceProvider $sp = null): string
    {
        $signingCert = $this->certificates->activeSigningCertificate();
        $certBody = $this->stripPem($signingCert->certificate);
        $entityId = $this->entityId();
        $ssoUrl = url('/saml/sso');
        $sloUrl = url('/saml/slo');
        $nameIdFormat = $sp->name_id_format ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';

        $xml = <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
  <md:IDPSSODescriptor WantAuthnRequestsSigned="false" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
        <ds:X509Data>
          <ds:X509Certificate>{$certBody}</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
    </md:KeyDescriptor>
    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$sloUrl}"/>
    <md:NameIDFormat>{$nameIdFormat}</md:NameIDFormat>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$ssoUrl}"/>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$ssoUrl}"/>
  </md:IDPSSODescriptor>
</md:EntityDescriptor>
XML;

        return $xml;
    }

    /**
     * @return array{id: string, issuer: string, name_id: ?string, destination: ?string}
     */
    public function parseLogoutRequest(string $xml): array
    {
        $dom = XmlSecurity::loadSafely($xml);
        $xpath = $this->xpath($dom);

        $root = $xpath->query('//samlp:LogoutRequest')->item(0);

        if (! $root) {
            throw new \RuntimeException('Keine gültige LogoutRequest gefunden.');
        }

        return [
            'id' => $root->getAttribute('ID'),
            'issuer' => trim((string) $xpath->evaluate('string(//saml:Issuer)', $root)),
            'name_id' => trim((string) $xpath->evaluate('string(//saml:NameID)', $root)) ?: null,
            'destination' => $root->getAttribute('Destination') ?: null,
        ];
    }

    public function buildLogoutResponse(string $inResponseTo, string $destination): string
    {
        $cert = $this->certificates->activeSigningCertificate();
        $id = '_'.Str::uuid();
        $issueInstant = now()->toIso8601ZuluString();
        $issuer = $this->entityId();

        $xml = <<<XML
<samlp:LogoutResponse xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$id}" Version="2.0" IssueInstant="{$issueInstant}" Destination="{$destination}" InResponseTo="{$inResponseTo}">
  <saml:Issuer>{$issuer}</saml:Issuer>
  <samlp:Status>
    <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
  </samlp:Status>
</samlp:LogoutResponse>
XML;

        $dom = XmlSecurity::loadSafely($xml);
        $signed = XmlSecurity::sign($dom, $cert->private_key_encrypted, $cert->certificate);

        return base64_encode($signed);
    }

    private function stripPem(string $pem): string
    {
        return trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r"], '', $pem));
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        return $xpath;
    }
}
