<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Service;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\TimeoutException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Support\Arr;
use LuroWeb\KsefFoundry\Support\Json;
use LuroWeb\KsefFoundry\Support\PageGuard;
use LuroWeb\KsefFoundry\Support\SensitiveString;

/**
 * Management of long-lived KSeF tokens. Creating a token requires a JWT obtained by an XAdES (certificate)
 * login, and works only in a Nip/InternalId context.
 */
final class KsefTokenService
{
    private readonly \Closure $sleeper;

    /** @param (\Closure(float): void)|null $sleeper */
    public function __construct(private readonly AuthorizedClient $api, ?\Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
    }

    /**
     * POST /tokens. The token becomes usable once its status is Active (see awaitActive()).
     *
     * @param list<TokenPermission> $permissions
     */
    public function create(array $permissions, string $description): CreatedKsefToken
    {
        if ($permissions === []) {
            throw new ConfigurationException('At least one token permission is required.');
        }
        $len = mb_strlen($description);
        if ($len < 5 || $len > 256) {
            throw new ConfigurationException('Token description must be 5..256 characters.');
        }
        $r = $this->api->send(new HttpRequest('POST', '/tokens', body: Json::encode([
            'permissions' => array_map(static fn (TokenPermission $p): string => $p->value, $permissions),
            'description' => $description,
        ])));
        $a = $r->json();

        return new CreatedKsefToken(Arr::string($a, 'referenceNumber'), new SensitiveString(Arr::string($a, 'token')));
    }

    /** GET /tokens/{referenceNumber} */
    public function get(string $referenceNumber): KsefTokenInfo
    {
        $r = $this->api->send(new HttpRequest('GET', '/tokens/' . rawurlencode($referenceNumber), idempotent: true));

        return KsefTokenInfo::fromArray($r->json());
    }

    /**
     * GET /tokens (one page).
     *
     * @param list<TokenStatus> $statuses
     */
    public function list(
        array $statuses = [],
        ?string $description = null,
        ?string $authorIdentifier = null,
        ?string $authorIdentifierType = null,
        ?int $pageSize = null,
        ?string $continuationToken = null,
    ): KsefTokenPage {
        $query = [
            'status' => array_map(static fn (TokenStatus $s): string => $s->value, $statuses),
            'description' => $description,
            'authorIdentifier' => $authorIdentifier,
            'authorIdentifierType' => $authorIdentifierType,
            'pageSize' => $pageSize,
        ];
        $headers = $continuationToken !== null ? ['x-continuation-token' => $continuationToken] : [];
        $r = $this->api->send(new HttpRequest('GET', '/tokens', query: $query, idempotent: true, headers: $headers));

        return KsefTokenPage::fromArray($r->json());
    }

    /**
     * Lazily walks all tokens.
     *
     * @param list<TokenStatus> $statuses
     * @return \Generator<int, KsefTokenInfo>
     */
    public function iterate(array $statuses = [], ?int $pageSize = null): \Generator
    {
        $token = null;
        $guard = new PageGuard();
        do {
            $page = $this->list($statuses, pageSize: $pageSize, continuationToken: $token);
            yield from $page->tokens;
            $token = $page->continuationToken;
            $guard->advance($token);
        } while ($token !== null);
    }

    /** DELETE /tokens/{referenceNumber} (204) */
    public function revoke(string $referenceNumber): void
    {
        $this->api->send(new HttpRequest('DELETE', '/tokens/' . rawurlencode($referenceNumber), idempotent: true));
    }

    /** Polls GET /tokens/{ref} until status Active (or fails on Failed/Revoked/timeout). */
    public function awaitActive(string $referenceNumber, float $timeoutSeconds = 60.0, float $intervalSeconds = 1.0): KsefTokenInfo
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $info = $this->get($referenceNumber);
            if ($info->status === TokenStatus::Active) {
                return $info;
            }
            if ($info->status === TokenStatus::Failed || $info->status === TokenStatus::Revoked) {
                throw new ConfigurationException('KSeF token ended in status ' . $info->status->value . '.');
            }
            if (microtime(true) + $intervalSeconds >= $deadline) {
                throw new TimeoutException('KSeF token did not become Active in time.');
            }
            ($this->sleeper)($intervalSeconds);
        }
    }
}
