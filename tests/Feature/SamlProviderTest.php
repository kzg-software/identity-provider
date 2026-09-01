<?php

namespace Tests\Feature;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\SamlAttributeMapping;
use App\Models\SamlServiceProvider;
use App\Models\SystemSetting;
use App\Models\User;
use App\Saml\SamlCertificateService;
use App\Saml\SamlIdpService;
use App\Saml\XmlSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SamlProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createSp(array $overrides = []): SamlServiceProvider
    {
        $application = Application::create([
            'name' => 'Demo SAML SP',
            'slug' => 'demo-saml-sp-'.Str::random(6),
            'consent_required' => false,
            'consent_mode' => 'skip',
            'login_mode' => 'user_choice',
            'is_active' => true,
        ]);

        $sp = SamlServiceProvider::create(array_merge([
            'application_id' => $application->id,
            'name' => 'Demo SAML SP',
            'entity_id' => 'https://sp.example.test/metadata',
            'acs_url' => 'https://sp.example.test/saml/acs',
            'slo_url' => 'https://sp.example.test/saml/slo',
            'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress',
            'sign_assertions' => true,
            'sign_responses' => true,
            'encrypt_assertions' => false,
            'require_signed_requests' => false,
            'is_active' => true,
        ], $overrides));

        foreach (['uid' => 'username', 'mail' => 'email', 'displayName' => 'display_name', 'groups' => 'groups'] as $samlAttr => $userAttr) {
            SamlAttributeMapping::create([
                'saml_service_provider_id' => $sp->id,
                'saml_attribute' => $samlAttr,
                'user_attribute' => $userAttr,
            ]);
        }

        return $sp;
    }

    private function loginUser(): User
    {
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'username' => 'jdoe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'name' => 'John Doe',
            'display_name' => 'John Doe',
            'email' => 'jdoe@example.test',
            'auth_source' => 'local',
            'is_admin' => false,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);

        $this->post(route('login.attempt'), ['username' => 'jdoe', 'password' => 'Password123!'])
            ->assertRedirect(route('dashboard'));

        return $user;
    }

    private function buildAuthnRequestXml(string $issuer, string $acsUrl, ?string $id = null): array
    {
        $id = $id ?? '_'.Str::uuid();
        $xml = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$id}" Version="2.0" IssueInstant="{$this->now()}" Destination="http://localhost/saml/sso" AssertionConsumerServiceURL="{$acsUrl}" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
  <saml:Issuer>{$issuer}</saml:Issuer>
  <samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress" AllowCreate="true"/>
</samlp:AuthnRequest>
XML;

        return [$id, $xml];
    }

    private function now(): string
    {
        return now()->toIso8601ZuluString();
    }

    public function test_metadata_endpoint_publishes_valid_xml_with_certificate(): void
    {
        SystemSetting::set('installed', '1');

        $response = $this->get('/saml/metadata')->assertOk();
        $response->assertHeader('Content-Type', 'application/samlmetadata+xml');

        $xml = $response->getContent();
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringContainsString('X509Certificate', $xml);
        $this->assertStringContainsString('SingleSignOnService', $xml);
    }

    public function test_authn_request_produces_signed_assertion_verifiable_with_idp_certificate(): void
    {
        $sp = $this->createSp();
        $user = $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $response = $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();
        $response->assertViewIs('saml.auto_submit');

        $samlResponseB64 = $response->viewData('samlResponse');
        $responseXml = base64_decode($samlResponseB64);

        $this->assertStringContainsString('<saml:Assertion', $responseXml);
        $this->assertStringContainsString($user->email, $responseXml);
        $this->assertStringContainsString('<ds:Signature', $responseXml);

        $cert = app(SamlCertificateService::class)->activeSigningCertificate();
        $this->assertTrue(XmlSecurity::verify($responseXml, $cert->certificate));
    }

    public function test_sign_assertions_disabled_produces_an_unsigned_assertion(): void
    {
        // buildSignedResponse() used to sign the <Assertion> unconditionally,
        // ignoring this flag entirely - only the outer <Response> signature
        // was actually optional. Admins unchecking "Assertions signieren"
        // (e.g. to debug a picky SP) got a signed assertion anyway.
        $sp = $this->createSp(['sign_assertions' => false, 'sign_responses' => true]);
        $user = $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $response = $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();
        $responseXml = base64_decode($response->viewData('samlResponse'));

        // The Response itself is still signed (sign_responses stayed true) -
        // exactly one <ds:Signature> element, belonging to the Response, not
        // the Assertion. (substr_count on '<ds:Signature' alone would also
        // match '<ds:SignatureValue>'/'<ds:SignatureMethod', hence the
        // trailing space to match only the element's opening tag.)
        $this->assertSame(1, substr_count($responseXml, '<ds:Signature '));
        $this->assertStringContainsString($user->email, $responseXml);

        $cert = app(SamlCertificateService::class)->activeSigningCertificate();
        $this->assertTrue(XmlSecurity::verify($responseXml, $cert->certificate));
    }

    public function test_sign_responses_and_assertions_can_both_be_disabled(): void
    {
        $sp = $this->createSp(['sign_assertions' => false, 'sign_responses' => false]);
        $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $response = $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();
        $responseXml = base64_decode($response->viewData('samlResponse'));

        $this->assertStringNotContainsString('<ds:Signature', $responseXml);
        $this->assertStringContainsString('<saml:Assertion', $responseXml);
    }

    public function test_replayed_authn_request_id_is_rejected(): void
    {
        $sp = $this->createSp();
        $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();

        // Replaying the exact same AuthnRequest ID must be rejected.
        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertStatus(400);
    }

    public function test_unsigned_authn_request_is_rejected_when_sp_requires_signature(): void
    {
        $sp = $this->createSp(['require_signed_requests' => true]);
        $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        // Redirect binding cannot carry a verifiable XML signature in this
        // implementation, so a signature-requiring SP must reject it.
        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertStatus(400);
    }

    public function test_access_policy_deny_blocks_saml_login(): void
    {
        $sp = $this->createSp();
        $user = $this->loginUser();

        AccessPolicy::create([
            'application_id' => $sp->application_id,
            'effect' => 'deny',
            'subject_type' => 'user',
            'subject_value' => $user->username,
            'priority' => 10,
        ]);

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertStatus(403);
    }

    public function test_access_policy_allow_permits_matching_group(): void
    {
        $sp = $this->createSp();
        $user = $this->loginUser();

        AccessPolicy::create([
            'application_id' => $sp->application_id,
            'effect' => 'allow',
            'subject_type' => 'group',
            'subject_value' => 'IT',
            'priority' => 0,
        ]);

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        // User has no group memberships, so the allow rule does not match -> denied.
        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertStatus(403);
    }

    public function test_attribute_mapping_produces_expected_saml_attributes(): void
    {
        $sp = $this->createSp();
        $user = $this->loginUser();

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $response = $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();
        $responseXml = base64_decode($response->viewData('samlResponse'));

        $this->assertStringContainsString('Name="uid"', $responseXml);
        $this->assertStringContainsString('Name="mail"', $responseXml);
        $this->assertStringContainsString($user->username, $responseXml);
    }

    public function test_unauthenticated_user_is_redirected_to_login_and_resumes_after_login(): void
    {
        $sp = $this->createSp();
        SystemSetting::set('installed', '1');

        $user = User::factory()->create([
            'username' => 'asmith',
            'name' => 'Alice Smith',
            'email' => 'asmith@example.test',
            'auth_source' => 'local',
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);

        [$id, $xml] = $this->buildAuthnRequestXml($sp->entity_id, $sp->acs_url);
        $encoded = base64_encode(gzdeflate($xml));

        $this->get('/saml/sso?'.http_build_query(['SAMLRequest' => $encoded]))
            ->assertRedirect(route('login'));

        $this->post(route('login.attempt'), ['username' => 'asmith', 'password' => 'Password123!'])
            ->assertRedirect(route('saml.sso.resume'));

        $this->get(route('saml.sso.resume'))->assertOk()->assertViewIs('saml.auto_submit');
    }

    public function test_single_logout_ends_session_and_returns_signed_logout_response(): void
    {
        $sp = $this->createSp();
        $this->loginUser();

        $logoutId = '_'.Str::uuid();
        $logoutXml = <<<XML
<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$logoutId}" Version="2.0" IssueInstant="{$this->now()}" Destination="http://localhost/saml/slo">
  <saml:Issuer>{$sp->entity_id}</saml:Issuer>
  <saml:NameID>jdoe@example.test</saml:NameID>
</samlp:LogoutRequest>
XML;

        $encoded = base64_encode(gzdeflate($logoutXml));

        $response = $this->get('/saml/slo?'.http_build_query(['SAMLRequest' => $encoded]))->assertOk();
        $response->assertViewIs('saml.auto_submit');

        $logoutResponseXml = base64_decode($response->viewData('samlResponse'));
        $this->assertStringContainsString('<samlp:LogoutResponse', $logoutResponseXml);
        $this->assertStringContainsString('Success', $logoutResponseXml);

        $this->assertGuest();
    }
}
