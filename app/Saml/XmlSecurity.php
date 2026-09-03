<?php

namespace App\Saml;

use DOMDocument;
use DOMElement;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Thin wrapper around robrichards/xmlseclibs (the library onelogin/php-saml
 * itself uses for signing) so we can sign both top-level SAML messages
 * (Response, LogoutRequest, ...) and embedded <Assertion> elements, which
 * OneLogin\Saml2\Utils::addSign() does not support directly.
 *
 * No custom cryptography is implemented here — canonicalization, digesting
 * and signing are entirely delegated to xmlseclibs.
 */
class XmlSecurity
{
    public static function sign(DOMDocument $dom, string $privateKeyPem, string $certificatePem): string
    {
        $rootNode = $dom->firstChild;

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($privateKeyPem, false);

        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReferenceList(
            [$rootNode],
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID']
        );
        $dsig->sign($key);
        $dsig->add509Cert($certificatePem, true);

        $insertBefore = $rootNode->firstChild;
        $issuerNodes = Utils::query($dom, '/'.$rootNode->tagName.'/saml:Issuer');
        if ($issuerNodes->length === 1) {
            $insertBefore = $issuerNodes->item(0)->nextSibling;
        }

        $dsig->insertSignature($rootNode, $insertBefore);

        return $dom->saveXML();
    }

    /**
     * @return bool whether $xml carries a valid signature by $certificatePem
     */
    public static function verify(string $xml, string $certificatePem): bool
    {
        try {
            return (bool) Utils::validateSign($xml, $certificatePem);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse XML safely (no external entities / XXE, no DTD), returning a DOMDocument.
     */
    public static function loadSafely(string $xml): DOMDocument
    {
        // SAML-Nachrichten haben nie eine DTD. Eine DOCTYPE-Deklaration ist
        // daher immer verdaechtig (Entity-Expansion / XXE) und wird abgewiesen,
        // bevor der Parser sie ueberhaupt sieht.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('DOCTYPE-Deklarationen sind in SAML-Nachrichten nicht erlaubt.');
        }

        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        // Ausdruecklich OHNE LIBXML_NOENT und LIBXML_DTDLOAD: Entities werden
        // nicht ersetzt, keine externe DTD wird geladen.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new \RuntimeException('Ungültiges SAML-XML.');
        }

        if ($dom->doctype !== null) {
            throw new \RuntimeException('DOCTYPE-Deklarationen sind in SAML-Nachrichten nicht erlaubt.');
        }

        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('xmlns:xi')) {
                throw new \RuntimeException('XInclude in SAML-Nachrichten ist nicht erlaubt.');
            }
        }

        return $dom;
    }
}
