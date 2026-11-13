<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Context\SubjectIdentifierType;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;

/**
 * Builds the unsigned AuthTokenRequest (schemas/xsd/auth/schemat_auth_v2-1.xsd).
 */
final class AuthTokenRequestBuilder
{
    public const NAMESPACE_URI = 'http://ksef.mf.gov.pl/auth/token/2.1';

    public function build(
        string $challenge,
        ContextIdentifier $context,
        SubjectIdentifierType $subjectIdentifierType,
    ): string {
        if (preg_match('/^\d{8}-CR-[A-F0-9]{10}-[A-F0-9]{10}-[A-F0-9]{2}$/D', $challenge) !== 1) {
            throw new ConfigurationException('Challenge does not have the format returned by POST /auth/challenge.');
        }
        // Re-validate by type: a ContextIdentifier is also validated at construction, this guards future factories.
        ContextIdentifier::of($context->type, $context->value);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $root = $dom->createElementNS(self::NAMESPACE_URI, 'AuthTokenRequest');
        $dom->appendChild($root);
        $challengeEl = $dom->createElementNS(self::NAMESPACE_URI, 'Challenge');
        $challengeEl->appendChild($dom->createTextNode($challenge)); // createTextNode: values are escaped, never parsed
        $root->appendChild($challengeEl);
        $ctx = $dom->createElementNS(self::NAMESPACE_URI, 'ContextIdentifier');
        $idEl = $dom->createElementNS(self::NAMESPACE_URI, $context->type->value);
        $idEl->appendChild($dom->createTextNode($context->value));
        $ctx->appendChild($idEl);
        $root->appendChild($ctx);
        $sitEl = $dom->createElementNS(self::NAMESPACE_URI, 'SubjectIdentifierType');
        $sitEl->appendChild($dom->createTextNode($subjectIdentifierType->value));
        $root->appendChild($sitEl);
        $xml = $dom->saveXML();

        return $xml === false ? '' : $xml;
    }
}
