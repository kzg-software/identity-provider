<?php

namespace App\Saml;

use App\Models\SamlCertificate;

class SamlCertificateService
{
    /**
     * Return the active signing certificate, generating a self-signed one if none exists.
     */
    public function activeSigningCertificate(): SamlCertificate
    {
        return SamlCertificate::query()
            ->where('type', 'signing')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first() ?? $this->generateSelfSigned('signing');
    }

    public function rotate(string $type = 'signing'): SamlCertificate
    {
        SamlCertificate::query()->where('type', $type)->where('is_active', true)->update(['is_active' => false]);

        return $this->generateSelfSigned($type);
    }

    public function generateSelfSigned(string $type = 'signing', int $validDays = 3650): SamlCertificate
    {
        $privateKeyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKeyResource === false) {
            throw new \RuntimeException('Konnte keinen RSA-Schlüssel erzeugen: '.openssl_error_string());
        }

        $issuer = config('app.name', 'Auth').' SAML IdP';

        $csr = openssl_csr_new(
            ['commonName' => $issuer],
            $privateKeyResource,
            ['digest_alg' => 'sha256']
        );

        $x509 = openssl_csr_sign($csr, null, $privateKeyResource, $validDays, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new \RuntimeException('Konnte kein Zertifikat erzeugen: '.openssl_error_string());
        }

        openssl_x509_export($x509, $certificatePem);
        openssl_pkey_export($privateKeyResource, $privateKeyPem);

        $certificate = SamlCertificate::create([
            'name' => $issuer.' ('.now()->format('Y-m-d').')',
            'type' => $type,
            'certificate' => $certificatePem,
            'private_key_encrypted' => $privateKeyPem,
            'fingerprint' => $this->fingerprint($certificatePem),
            'algorithm' => 'sha256',
            'issued_at' => now(),
            'expires_at' => now()->addDays($validDays),
            'is_active' => true,
        ]);

        return $certificate;
    }

    public function fingerprint(string $certificatePem): string
    {
        return strtoupper(\OneLogin\Saml2\Utils::calculateX509Fingerprint($certificatePem, 'sha256'));
    }

    /**
     * All certificates whose public part should still be published in metadata.
     */
    public function publishable(string $type = 'signing')
    {
        return SamlCertificate::query()->where('type', $type)->orderByDesc('is_active')->orderByDesc('created_at')->get();
    }
}
