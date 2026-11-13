<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Context\ContextIdentifier;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\HttpTransport;
use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\Json;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/**
 * Raw calls of the authentication group. Methods that start or finish an authentication are
 * non-idempotent (never auto-retried after an ambiguous failure, only after 429).
 */
final class AuthApi
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /** POST /auth/challenge */
    public function challenge(): AuthChallenge
    {
        $r = $this->transport->send(new HttpRequest('POST', '/auth/challenge'));
        $a = $r->json();

        return new AuthChallenge(
            Arr::string($a, 'challenge'),
            Arr::date($a, 'timestamp'),
            Arr::int($a, 'timestampMs'),
            Arr::stringOrNull($a, 'clientIp') ?? '',
        );
    }

    /** POST /auth/ksef-token */
    public function submitKsefToken(
        AuthChallenge $challenge,
        ContextIdentifier $context,
        #[\SensitiveParameter] string $encryptedTokenBase64,
        ?string $publicKeyId,
    ): AuthInit {
        $body = [
            'challenge' => $challenge->challenge,
            'contextIdentifier' => $context->toArray(),
            'encryptedToken' => $encryptedTokenBase64,
        ];
        if ($publicKeyId !== null) {
            $body['publicKeyId'] = $publicKeyId;
        }
        $r = $this->transport->send(new HttpRequest('POST', '/auth/ksef-token', body: Json::encode($body)));

        return AuthInit::fromArray($r->json());
    }

    /** POST /auth/xades-signature (application/xml) */
    public function submitXades(string $signedXml): AuthInit
    {
        $r = $this->transport->send(new HttpRequest(
            'POST',
            '/auth/xades-signature',
            body: $signedXml,
            contentType: 'application/xml',
        ));

        return AuthInit::fromArray($r->json());
    }

    /** GET /auth/{referenceNumber} with the temporary authenticationToken as Bearer. */
    public function status(string $referenceNumber, #[\SensitiveParameter] SensitiveString $authenticationToken): AuthStatus
    {
        $r = $this->transport->send(new HttpRequest(
            'GET',
            '/auth/' . rawurlencode($referenceNumber),
            bearer: $authenticationToken,
            idempotent: true,
        ));

        return AuthStatus::fromArray($r->json());
    }

    /** POST /auth/token/redeem (one-shot). */
    public function redeem(#[\SensitiveParameter] SensitiveString $authenticationToken): TokenPair
    {
        $r = $this->transport->send(new HttpRequest('POST', '/auth/token/redeem', bearer: $authenticationToken));
        $a = $r->json();
        $access = Arr::arr($a, 'accessToken');
        $refresh = Arr::arr($a, 'refreshToken');

        return new TokenPair(
            new SensitiveString(Arr::string($access, 'token')),
            Arr::date($access, 'validUntil'),
            new SensitiveString(Arr::string($refresh, 'token')),
            Arr::date($refresh, 'validUntil'),
        );
    }

    /**
     * POST /auth/token/refresh (refresh token as Bearer). Returns the new access token and its expiry.
     *
     * @return array{0: SensitiveString, 1: \DateTimeImmutable}
     */
    public function refresh(#[\SensitiveParameter] SensitiveString $refreshToken): array
    {
        $r = $this->transport->send(new HttpRequest('POST', '/auth/token/refresh', bearer: $refreshToken));
        $access = Arr::arr($r->json(), 'accessToken');

        return [new SensitiveString(Arr::string($access, 'token')), Arr::date($access, 'validUntil')];
    }
}
