<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Support\PageGuard;

/** GET /auth/sessions, DELETE /auth/sessions/current|{ref}. */
final class AuthSessionService
{
    public function __construct(private readonly AuthorizedClient $api)
    {
    }

    /** One page (pageSize 10..100). */
    public function list(?int $pageSize = null, ?string $continuationToken = null): AuthSessionPage
    {
        $headers = $continuationToken !== null ? ['x-continuation-token' => $continuationToken] : [];
        $r = $this->api->send(new HttpRequest('GET', '/auth/sessions', query: ['pageSize' => $pageSize], idempotent: true, headers: $headers));

        return AuthSessionPage::fromArray($r->json());
    }

    /** @return \Generator<int, AuthSession> */
    public function iterate(?int $pageSize = null): \Generator
    {
        $token = null;
        $guard = new PageGuard();
        do {
            $page = $this->list($pageSize, $token);
            yield from $page->items;
            $token = $page->continuationToken;
            $guard->advance($token);
        } while ($token !== null);
    }

    /** Revokes the session of the token used for the call (its refresh token stops working). */
    public function revokeCurrent(): void
    {
        $this->api->send(new HttpRequest('DELETE', '/auth/sessions/current', idempotent: true));
    }

    public function revoke(string $referenceNumber): void
    {
        $this->api->send(new HttpRequest('DELETE', '/auth/sessions/' . rawurlencode($referenceNumber), idempotent: true));
    }
}
