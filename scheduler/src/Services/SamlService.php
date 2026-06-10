<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Minimal SAML 2.0 Service Provider — dependency-free.
 *
 * Supports the flow used by Azure AD / Entra ID, Google Workspace, Okta,
 * OneLogin and ADFS with default settings:
 *   - SP-initiated SSO via HTTP-Redirect binding (deflated AuthnRequest)
 *   - Assertion consumption via HTTP-POST binding
 *   - XML-DSig validation (RSA-SHA256 / RSA-SHA1, exclusive C14N) of the
 *     Response and/or Assertion signature against the configured IdP cert
 *   - Conditions (NotBefore / NotOnOrAfter, AudienceRestriction),
 *     SubjectConfirmationData (Recipient, InResponseTo) checks
 *
 * For high-assurance deployments or exotic IdP configurations (encrypted
 * assertions, HTTP-Artifact, signature inheritance edge cases) install
 * onelogin/php-saml and swap this class behind the same interface.
 */
final class SamlService
{
    private const CLOCK_SKEW = 120; // seconds of tolerated clock drift
    private const NS_PROTOCOL = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const NS_ASSERTION = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && ($this->config['idp_sso_url'] ?? '') !== ''
            && ($this->config['idp_x509_cert'] ?? '') !== '';
    }

    // -----------------------------------------------------------------
    // SP-initiated login (HTTP-Redirect binding)
    // -----------------------------------------------------------------

    /**
     * Build the IdP redirect URL for a new AuthnRequest and return
     * [url, requestId]; the caller stores requestId for InResponseTo checks.
     */
    public function buildLoginRedirect(string $relayState = '/'): array
    {
        $requestId = '_' . bin2hex(random_bytes(16));
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $xml = sprintf(
            '<samlp:AuthnRequest xmlns:samlp="%s" xmlns:saml="%s" ID="%s" Version="2.0" '
            . 'IssueInstant="%s" Destination="%s" AssertionConsumerServiceURL="%s" '
            . 'ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
            . '<saml:Issuer>%s</saml:Issuer>'
            . '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>'
            . '</samlp:AuthnRequest>',
            self::NS_PROTOCOL,
            self::NS_ASSERTION,
            $requestId,
            $issueInstant,
            htmlspecialchars((string) $this->config['idp_sso_url'], ENT_XML1),
            htmlspecialchars((string) $this->config['acs_url'], ENT_XML1),
            htmlspecialchars((string) $this->config['sp_entity_id'], ENT_XML1)
        );

        $query = http_build_query([
            'SAMLRequest' => base64_encode(gzdeflate($xml)),
            'RelayState' => $relayState,
        ]);
        $separator = str_contains((string) $this->config['idp_sso_url'], '?') ? '&' : '?';

        return [$this->config['idp_sso_url'] . $separator . $query, $requestId];
    }

    // -----------------------------------------------------------------
    // Assertion consumption (HTTP-POST binding)
    // -----------------------------------------------------------------

    /**
     * Validate a base64 SAMLResponse and return the authenticated identity.
     *
     * @return array{email: string, name_id: string, attributes: array<string, string[]>}
     * @throws \RuntimeException on any validation failure
     */
    public function consumeResponse(string $samlResponseB64, ?string $expectedInResponseTo = null): array
    {
        $xml = base64_decode($samlResponseB64, true);
        if ($xml === false || $xml === '') {
            throw new \RuntimeException('SAML: response is not valid base64');
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new \RuntimeException('SAML: response is not valid XML');
        }
        // Reject documents carrying a DTD (XXE / entity-expansion hardening).
        foreach ($doc->childNodes as $child) {
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                throw new \RuntimeException('SAML: DTD in response rejected');
            }
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('samlp', self::NS_PROTOCOL);
        $xp->registerNamespace('saml', self::NS_ASSERTION);
        $xp->registerNamespace('ds', self::NS_DSIG);

        // 1. Status must be Success.
        $status = $xp->evaluate('string(/samlp:Response/samlp:Status/samlp:StatusCode/@Value)');
        if ($status !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            throw new \RuntimeException('SAML: IdP returned non-success status: ' . $status);
        }

        // 2. Exactly one (unencrypted) assertion.
        if ($xp->query('/samlp:Response/saml:EncryptedAssertion')->length > 0) {
            throw new \RuntimeException('SAML: encrypted assertions are not supported by the built-in SP '
                . '(disable assertion encryption at the IdP or install onelogin/php-saml)');
        }
        $assertions = $xp->query('/samlp:Response/saml:Assertion');
        if ($assertions->length !== 1) {
            throw new \RuntimeException('SAML: expected exactly one assertion, got ' . $assertions->length);
        }
        /** @var \DOMElement $assertion */
        $assertion = $assertions->item(0);

        // 3. Signature: response-level or assertion-level, at least one must verify.
        $responseSigned = $this->verifyEnvelopedSignature($doc->documentElement, $xp);
        $assertionSigned = $this->verifyEnvelopedSignature($assertion, $xp);
        if (!$responseSigned && !$assertionSigned) {
            throw new \RuntimeException('SAML: no valid signature found on response or assertion');
        }

        // 4. Issuer must match the configured IdP.
        $issuer = trim((string) $xp->evaluate('string(./saml:Issuer)', $assertion));
        $expectedIssuer = (string) ($this->config['idp_entity_id'] ?? '');
        if ($expectedIssuer !== '' && $issuer !== $expectedIssuer) {
            throw new \RuntimeException("SAML: unexpected issuer '$issuer'");
        }

        // 5. Conditions: validity window + audience.
        $now = time();
        $notBefore = (string) $xp->evaluate('string(./saml:Conditions/@NotBefore)', $assertion);
        $notOnOrAfter = (string) $xp->evaluate('string(./saml:Conditions/@NotOnOrAfter)', $assertion);
        if ($notBefore !== '' && strtotime($notBefore) - self::CLOCK_SKEW > $now) {
            throw new \RuntimeException('SAML: assertion not yet valid');
        }
        if ($notOnOrAfter !== '' && strtotime($notOnOrAfter) + self::CLOCK_SKEW <= $now) {
            throw new \RuntimeException('SAML: assertion has expired');
        }
        $audiences = [];
        foreach ($xp->query('./saml:Conditions/saml:AudienceRestriction/saml:Audience', $assertion) as $node) {
            $audiences[] = trim($node->textContent);
        }
        if ($audiences !== [] && !in_array((string) $this->config['sp_entity_id'], $audiences, true)) {
            throw new \RuntimeException('SAML: audience restriction does not include this SP');
        }

        // 6. SubjectConfirmationData: recipient + InResponseTo + expiry.
        $scd = $xp->query('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData', $assertion)->item(0);
        if ($scd instanceof \DOMElement) {
            $recipient = $scd->getAttribute('Recipient');
            if ($recipient !== '' && $recipient !== (string) $this->config['acs_url']) {
                throw new \RuntimeException('SAML: recipient mismatch');
            }
            $scdExpiry = $scd->getAttribute('NotOnOrAfter');
            if ($scdExpiry !== '' && strtotime($scdExpiry) + self::CLOCK_SKEW <= $now) {
                throw new \RuntimeException('SAML: subject confirmation has expired');
            }
            $inResponseTo = $scd->getAttribute('InResponseTo');
            if ($expectedInResponseTo !== null && $inResponseTo !== ''
                && $inResponseTo !== $expectedInResponseTo) {
                throw new \RuntimeException('SAML: InResponseTo does not match the original request');
            }
        }

        // 7. Identity: NameID plus attribute statement.
        $nameId = trim((string) $xp->evaluate('string(./saml:Subject/saml:NameID)', $assertion));
        $attributes = [];
        foreach ($xp->query('./saml:AttributeStatement/saml:Attribute', $assertion) as $attr) {
            /** @var \DOMElement $attr */
            $values = [];
            foreach ($xp->query('./saml:AttributeValue', $attr) as $value) {
                $values[] = trim($value->textContent);
            }
            $attributes[$attr->getAttribute('Name')] = $values;
        }

        $email = $this->resolveEmail($nameId, $attributes);
        if ($email === null) {
            throw new \RuntimeException('SAML: no email address in NameID or attributes');
        }

        return ['email' => $email, 'name_id' => $nameId, 'attributes' => $attributes];
    }

    /** SP metadata XML for registering this application at the IdP. */
    public function metadataXml(): string
    {
        return sprintf(
            '<?xml version="1.0"?>' . "\n"
            . '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="%s">'
            . '<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" '
            . 'protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            . '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" '
            . 'Location="%s" index="0" isDefault="true"/>'
            . '</md:SPSSODescriptor></md:EntityDescriptor>',
            htmlspecialchars((string) $this->config['sp_entity_id'], ENT_XML1),
            htmlspecialchars((string) $this->config['acs_url'], ENT_XML1)
        );
    }

    // -----------------------------------------------------------------
    // XML-DSig verification
    // -----------------------------------------------------------------

    /**
     * Verify the enveloped ds:Signature directly under $element (if any)
     * against the configured IdP certificate. Returns true only when a
     * signature exists AND verifies (digest + signature + reference scope).
     */
    private function verifyEnvelopedSignature(\DOMElement $element, \DOMXPath $xp): bool
    {
        $signature = $xp->query('./ds:Signature', $element)->item(0);
        if (!$signature instanceof \DOMElement) {
            return false;
        }
        $signedInfo = $xp->query('./ds:SignedInfo', $signature)->item(0);
        $sigValueNode = $xp->query('./ds:SignatureValue', $signature)->item(0);
        $reference = $xp->query('./ds:SignedInfo/ds:Reference', $signature)->item(0);
        if (!$signedInfo instanceof \DOMElement || $sigValueNode === null || !$reference instanceof \DOMElement) {
            throw new \RuntimeException('SAML: malformed signature element');
        }

        // The reference must point at the element that carries the signature.
        $uri = $reference->getAttribute('URI');
        if ($uri !== '' && $uri !== '#' . $element->getAttribute('ID')) {
            throw new \RuntimeException('SAML: signature reference does not cover the signed element');
        }

        // --- Digest check: canonicalize the element without its Signature. ---
        $digestMethod = (string) $xp->evaluate('string(./ds:DigestMethod/@Algorithm)', $reference);
        $digestValue = base64_decode(trim((string) $xp->evaluate('string(./ds:DigestValue)', $reference)), true);
        $hashAlgo = $this->hashAlgoFromUri($digestMethod);

        $clone = $element->cloneNode(true);
        $cloneDoc = new \DOMDocument();
        $cloneDoc->appendChild($cloneDoc->importNode($clone, true));
        $cloneXp = new \DOMXPath($cloneDoc);
        $cloneXp->registerNamespace('ds', self::NS_DSIG);
        $cloneSig = $cloneXp->query('./ds:Signature', $cloneDoc->documentElement)->item(0);
        if ($cloneSig !== null) {
            $cloneSig->parentNode->removeChild($cloneSig);
        }
        $canonical = $cloneDoc->documentElement->C14N(true, false);
        if (!hash_equals(hash($hashAlgo, $canonical, true), (string) $digestValue)) {
            throw new \RuntimeException('SAML: reference digest mismatch — content was modified');
        }

        // --- Signature check over canonicalized SignedInfo. ---
        $sigMethod = (string) $xp->evaluate('string(./ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signature);
        $opensslAlgo = match ($sigMethod) {
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
            default => throw new \RuntimeException("SAML: unsupported signature algorithm $sigMethod"),
        };
        $signatureValue = base64_decode(preg_replace('/\s+/', '', $sigValueNode->textContent), true);
        $publicKey = openssl_pkey_get_public($this->idpCertificatePem());
        if ($publicKey === false) {
            throw new \RuntimeException('SAML: configured IdP certificate is not a valid X.509 cert');
        }
        $ok = openssl_verify($signedInfo->C14N(true, false), (string) $signatureValue, $publicKey, $opensslAlgo);
        if ($ok !== 1) {
            throw new \RuntimeException('SAML: signature verification failed');
        }

        return true;
    }

    private function hashAlgoFromUri(string $uri): string
    {
        return match ($uri) {
            'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
            'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
            'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
            'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
            default => throw new \RuntimeException("SAML: unsupported digest algorithm $uri"),
        };
    }

    /** Accept the IdP cert as full PEM or bare base64 (as IdP consoles export it). */
    private function idpCertificatePem(): string
    {
        $cert = trim((string) $this->config['idp_x509_cert']);
        if (str_contains($cert, 'BEGIN CERTIFICATE')) {
            return $cert;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(preg_replace('/\s+/', '', $cert), 64, "\n")
            . '-----END CERTIFICATE-----';
    }

    private function resolveEmail(string $nameId, array $attributes): ?string
    {
        if (filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            return strtolower($nameId);
        }
        // Common attribute names across Azure AD, Google, Okta, ADFS.
        $candidates = [
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'urn:oid:0.9.2342.19200300.100.1.3',
            'email', 'mail', 'emailAddress', 'Email',
        ];
        foreach ($candidates as $name) {
            $value = $attributes[$name][0] ?? null;
            if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return strtolower($value);
            }
        }

        return null;
    }
}
