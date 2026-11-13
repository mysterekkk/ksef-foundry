<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

use LuroWeb\KsefFoundry\Auth\XadesSigner;

/**
 * Independent re-verification of our own XAdES output: re-parses the serialised XML, re-canonicalises every
 * reference and SignedInfo, recomputes digests and runs openssl_verify against the embedded certificate.
 */
final class XadesVerifier
{
    /** @return list<string> problems; empty means the signature is valid */
    public function verify(string $signedXml): array
    {
        $problems = [];
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->loadXML($signedXml)) {
            return ['not well-formed'];
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('ds', XadesSigner::DS);
        $xp->registerNamespace('xades', XadesSigner::XADES);
        $sigs = $xp->query('/*/ds:Signature');
        if ($sigs === false || $sigs->length !== 1) {
            return ['expected exactly one enveloped ds:Signature'];
        }
        $sig = $sigs->item(0);
        if (!$sig instanceof \DOMElement) {
            return ['no signature'];
        }
        $signedInfo = self::first($xp, 'ds:SignedInfo', $sig);
        if (!$signedInfo instanceof \DOMElement) {
            return ['no SignedInfo'];
        }
        $c14nAlg = self::first($xp, 'ds:CanonicalizationMethod/@Algorithm', $signedInfo)?->nodeValue;
        if ($c14nAlg !== XadesSigner::EXC_C14N) {
            $problems[] = 'SignedInfo must use exclusive c14n';
        }

        foreach ($xp->query('ds:Reference', $signedInfo) ?: [] as $ref) {
            if (!$ref instanceof \DOMElement) {
                continue;
            }
            $uri = $ref->getAttribute('URI');
            $algs = [];
            foreach ($xp->query('ds:Transforms/ds:Transform/@Algorithm', $ref) ?: [] as $a) {
                $algs[] = (string) $a->nodeValue;
            }
            if ($uri === '') {
                $copy = new \DOMDocument();
                $copy->preserveWhiteSpace = false;
                $copy->loadXML($signedXml);
                $cx = new \DOMXPath($copy);
                $cx->registerNamespace('ds', XadesSigner::DS);
                foreach (iterator_to_array($cx->query('/*/ds:Signature') ?: []) as $n) {
                    $n->parentNode?->removeChild($n);
                }
                $bytes = $copy->C14N(true, false);
                if (!in_array(XadesSigner::ENVELOPED, $algs, true)) {
                    $problems[] = 'document reference lacks enveloped-signature transform';
                }
            } else {
                $target = self::first($xp, '//*[@Id="' . ltrim($uri, '#') . '"]');
                if (!$target instanceof \DOMElement) {
                    $problems[] = 'reference target ' . $uri . ' not found';
                    continue;
                }
                $bytes = $target->C14N(true, false);
            }
            $digest = base64_encode(hash('sha256', $bytes, true));
            $declared = self::first($xp, 'ds:DigestValue', $ref)?->nodeValue;
            if ($declared !== $digest) {
                $problems[] = 'digest mismatch for reference "' . $uri . '"';
            }
        }

        $certB64 = (string) self::first($xp, 'ds:KeyInfo/ds:X509Data/ds:X509Certificate', $sig)?->nodeValue;
        $der = base64_decode($certB64, true);
        $pubKey = $der === false ? false : openssl_pkey_get_public("-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n");
        if ($pubKey === false) {
            return array_merge($problems, ['cannot read embedded certificate']);
        }
        $sigValue = base64_decode((string) self::first($xp, 'ds:SignatureValue', $sig)?->nodeValue, true);
        if ($sigValue === false) {
            return array_merge($problems, ['SignatureValue is not base64']);
        }
        $method = (string) self::first($xp, 'ds:SignatureMethod/@Algorithm', $signedInfo)?->nodeValue;
        $details = openssl_pkey_get_details($pubKey);
        $algo = match (true) {
            str_ends_with($method, 'sha512') => OPENSSL_ALGO_SHA512,
            str_ends_with($method, 'sha384') => OPENSSL_ALGO_SHA384,
            default => OPENSSL_ALGO_SHA256,
        };
        if (str_contains($method, 'ecdsa')) {
            $sigValue = $this->fixedRsToDer($sigValue);
        }
        $ok = openssl_verify($signedInfo->C14N(true, false), $sigValue, $pubKey, $algo);
        if ($ok !== 1) {
            $problems[] = 'signature value does not verify (' . ($details['type'] ?? '?') . ')';
        }

        return $problems;
    }

    private function fixedRsToDer(string $rs): string
    {
        $half = intdiv(strlen($rs), 2);
        $int = static function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '' || (ord($v[0]) & 0x80) !== 0) {
                $v = "\x00" . $v;
            }

            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $int(substr($rs, 0, $half)) . $int(substr($rs, $half));

        return "\x30" . (strlen($body) < 128 ? chr(strlen($body)) : "\x81" . chr(strlen($body))) . $body;
    }

    private static function first(\DOMXPath $xp, string $expr, ?\DOMNode $ctx = null): ?\DOMNode
    {
        $r = $ctx === null ? $xp->query($expr) : $xp->query($expr, $ctx);

        return $r === false ? null : $r->item(0);
    }
}
