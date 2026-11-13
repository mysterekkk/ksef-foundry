<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Exception\CertificateException;
use LuroWeb\KsefFoundry\Exception\XmlValidationException;
use LuroWeb\KsefFoundry\Support\X509;

/**
 * XAdES-BES enveloped signer for AuthTokenRequest, built only from standard primitives:
 * DOM exclusive C14N, openssl_sign, SHA-256 digests.
 *
 * Output structure: ds:Signature (last child of the root) with
 *  - Reference URI="" (enveloped-signature + exc-c14n transforms) to the document,
 *  - Reference Type=".../01903#SignedProperties" (exc-c14n) to xades:SignedProperties holding
 *    SigningTime and SigningCertificate(CertDigest SHA-256, IssuerSerial),
 *  - KeyInfo/X509Data/X509Certificate.
 * RSA uses PKCS#1 v1.5 + SHA-256; ECDSA uses fixed-size R||S (NOT DER), hash by curve size.
 *
 * Verified scope (LIVE TEST, api-test.ksef.mf.gov.pl, 2026-11-13): self-signed personal certificate
 * (serialNumber PNOPL-<pesel>), RSA-2048 and ECDSA P-256 keys, context Nip, SubjectIdentifierType
 * certificateSubject, AuthTokenRequest namespace 2.1. NOT verified live: qualified certificates/seals,
 * KSeF Authentication certificates, certificateFingerprint, other curves (P-384/P-521 are produced but untested).
 */
final class XadesSigner
{
    public const DS = 'http://www.w3.org/2000/09/xmldsig#';
    public const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    public const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    public const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    public const DIGEST_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    public const SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock unix time (SigningTime)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function sign(string $unsignedXml, #[\SensitiveParameter] CertificateCredentials $credentials): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($unsignedXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            throw new XmlValidationException('AuthTokenRequest XML is not well-formed.');
        }
        if ($dom->doctype !== null) {
            throw new XmlValidationException('DOCTYPE is not allowed in AuthTokenRequest.');
        }
        $root = $dom->documentElement;
        if ($dom->getElementsByTagNameNS(self::DS, 'Signature')->length > 0) {
            throw new XmlValidationException('Document is already signed.');
        }

        $key = $credentials->privateKey();
        $details = X509::keyInfo($key);
        [$sigAlg, $opensslAlg, $ecSize] = $this->algorithm($details);

        $cert = openssl_x509_read($credentials->certificatePem());
        if ($cert === false) {
            throw new CertificateException('Certificate cannot be parsed.');
        }
        $certDer = X509::der($cert);

        $docDigest = base64_encode(hash('sha256', $this->c14n($dom), true));

        $id = bin2hex(random_bytes(8));
        $sigId = 'Signature-' . $id;
        $spId = 'SignedProperties-' . $id;

        $sig = $dom->createElementNS(self::DS, 'ds:Signature');
        $sig->setAttribute('Id', $sigId);

        $signedInfo = $dom->createElementNS(self::DS, 'ds:SignedInfo');
        $signedInfo->appendChild($this->algEl($dom, 'ds:CanonicalizationMethod', self::EXC_C14N));
        $signedInfo->appendChild($this->algEl($dom, 'ds:SignatureMethod', $sigAlg));

        $ref1 = $dom->createElementNS(self::DS, 'ds:Reference');
        $ref1->setAttribute('Id', 'Reference-Document-' . $id);
        $ref1->setAttribute('URI', '');
        $tr = $dom->createElementNS(self::DS, 'ds:Transforms');
        $tr->appendChild($this->algEl($dom, 'ds:Transform', self::ENVELOPED));
        $tr->appendChild($this->algEl($dom, 'ds:Transform', self::EXC_C14N));
        $ref1->appendChild($tr);
        $ref1->appendChild($this->algEl($dom, 'ds:DigestMethod', self::DIGEST_SHA256));
        $ref1->appendChild($dom->createElementNS(self::DS, 'ds:DigestValue', $docDigest));
        $signedInfo->appendChild($ref1);

        $ref2 = $dom->createElementNS(self::DS, 'ds:Reference');
        $ref2->setAttribute('Type', self::SIGNED_PROPERTIES_TYPE);
        $ref2->setAttribute('URI', '#' . $spId);
        $tr2 = $dom->createElementNS(self::DS, 'ds:Transforms');
        $tr2->appendChild($this->algEl($dom, 'ds:Transform', self::EXC_C14N));
        $ref2->appendChild($tr2);
        $ref2->appendChild($this->algEl($dom, 'ds:DigestMethod', self::DIGEST_SHA256));
        $spDigestEl = $dom->createElementNS(self::DS, 'ds:DigestValue', '');
        $ref2->appendChild($spDigestEl);
        $signedInfo->appendChild($ref2);
        $sig->appendChild($signedInfo);

        $sigValueEl = $dom->createElementNS(self::DS, 'ds:SignatureValue', '');
        $sig->appendChild($sigValueEl);

        $keyInfo = $dom->createElementNS(self::DS, 'ds:KeyInfo');
        $x509Data = $dom->createElementNS(self::DS, 'ds:X509Data');
        $x509Data->appendChild($dom->createElementNS(self::DS, 'ds:X509Certificate', base64_encode($certDer)));
        $keyInfo->appendChild($x509Data);
        $sig->appendChild($keyInfo);

        $object = $dom->createElementNS(self::DS, 'ds:Object');
        $qp = $dom->createElementNS(self::XADES, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#' . $sigId);
        $sp = $dom->createElementNS(self::XADES, 'xades:SignedProperties');
        $sp->setAttribute('Id', $spId);
        $ssp = $dom->createElementNS(self::XADES, 'xades:SignedSignatureProperties');
        $ssp->appendChild($dom->createElementNS(self::XADES, 'xades:SigningTime', gmdate('Y-m-d\TH:i:s\Z', ($this->clock)())));
        $sc = $dom->createElementNS(self::XADES, 'xades:SigningCertificate');
        $c = $dom->createElementNS(self::XADES, 'xades:Cert');
        $cd = $dom->createElementNS(self::XADES, 'xades:CertDigest');
        $cd->appendChild($this->algEl($dom, 'ds:DigestMethod', self::DIGEST_SHA256));
        $cd->appendChild($dom->createElementNS(self::DS, 'ds:DigestValue', base64_encode(hash('sha256', $certDer, true))));
        $c->appendChild($cd);
        $is = $dom->createElementNS(self::XADES, 'xades:IssuerSerial');
        [$issuerName, $serial] = $this->issuerSerial($cert);
        $is->appendChild($dom->createElementNS(self::DS, 'ds:X509IssuerName', $issuerName));
        $is->appendChild($dom->createElementNS(self::DS, 'ds:X509SerialNumber', $serial));
        $c->appendChild($is);
        $sc->appendChild($c);
        $ssp->appendChild($sc);
        $sp->appendChild($ssp);
        $qp->appendChild($sp);
        $object->appendChild($qp);
        $sig->appendChild($object);

        $root->appendChild($sig);

        // Digest of SignedProperties as it sits in the final document (exclusive C14N of the subtree).
        $spDigestEl->nodeValue = base64_encode(hash('sha256', $this->c14nNode($sp), true));

        $signedInfoC14n = $this->c14nNode($signedInfo);
        $raw = null;
        if (!openssl_sign($signedInfoC14n, $raw, $key, $opensslAlg) || !is_string($raw)) {
            throw new CertificateException('Signing failed.');
        }
        if ($ecSize !== null) {
            $raw = $this->derToFixedRs($raw, $ecSize);
        }
        $sigValueEl->nodeValue = base64_encode($raw);

        $out = $dom->saveXML();
        if ($out === false) {
            throw new XmlValidationException('Cannot serialise signed XML.');
        }

        return $out;
    }

    /**
     * @param array{type: int, bits: int} $details
     * @return array{0: string, 1: int, 2: int|null} signature URI, openssl algo, EC coordinate size
     */
    private function algorithm(array $details): array
    {
        $type = $details['type'];
        $bits = $details['bits'];
        if ($type === OPENSSL_KEYTYPE_RSA) {
            return ['http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', OPENSSL_ALGO_SHA256, null];
        }
        if ($type === OPENSSL_KEYTYPE_EC) {
            return match (true) {
                $bits >= 521 => ['http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512', OPENSSL_ALGO_SHA512, 66],
                $bits >= 384 => ['http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384', OPENSSL_ALGO_SHA384, 48],
                default => ['http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256', OPENSSL_ALGO_SHA256, 32],
            };
        }
        throw new CertificateException('Only RSA and ECDSA keys are supported.');
    }

    private function algEl(\DOMDocument $dom, string $name, string $algorithm): \DOMElement
    {
        $e = $dom->createElementNS(self::DS, $name);
        $e->setAttribute('Algorithm', $algorithm);

        return $e;
    }

    private function c14n(\DOMDocument $dom): string
    {
        $r = $dom->C14N(true, false);

        return $r;
    }

    private function c14nNode(\DOMNode $node): string
    {
        return $node->C14N(true, false);
    }

    /** @return array{0: string, 1: string} RFC 2253 issuer DN and decimal serial */
    private function issuerSerial(\OpenSSLCertificate $cert): array
    {
        $info = X509::info($cert);
        $dn = implode(',', array_reverse($info['issuer']));
        $dec = '0';
        foreach (str_split($info['serialHex']) as $ch) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($ch));
        }

        return [$dn, $dec];
    }

    /** ASN.1 DER ECDSA-Sig-Value -> fixed-width R||S (XMLDSig / RFC 4050). */
    public function derToFixedRs(string $der, int $size): string
    {
        $o = 0;
        if (($der[$o++] ?? '') !== "\x30") {
            throw new CertificateException('Unexpected ECDSA signature encoding.');
        }
        $len = ord($der[$o++]);
        if ($len & 0x80) {
            $o += $len & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; ++$i) {
            if (($der[$o++] ?? '') !== "\x02") {
                throw new CertificateException('Unexpected ECDSA signature encoding.');
            }
            $l = ord($der[$o++]);
            $int = substr($der, $o, $l);
            $o += $l;
            $int = ltrim($int, "\x00");
            if (strlen($int) > $size) {
                throw new CertificateException('ECDSA component too large.');
            }
            $parts[] = str_pad($int, $size, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }
}
