<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Auth;

use LuroWeb\KsefFoundry\Exception\AuthenticationException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Http\HttpResponse;
use LuroWeb\KsefFoundry\Http\HttpTransport;

/**
 * Transport bound to one set of credentials: injects the bearer JWT and, on a single 401 (token revoked or
 * expired early), renews it once and repeats the call (a 401 means the request was not processed).
 */
final class AuthorizedClient
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly AuthenticationManager $auth,
        private readonly Credentials $credentials,
    ) {
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    public function send(#[\SensitiveParameter] HttpRequest $request): HttpResponse
    {
        $token = $this->auth->getAccessToken($this->credentials);
        try {
            return $this->transport->send($request->withBearer($token));
        } catch (AuthenticationException $e) {
            if ($e->statusCode() !== 401) {
                throw $e;
            }
            if ($this->auth->wasJustIssued($this->credentials)) {
                // The token we just obtained is already refused: do not log in again for the same request.
                $this->auth->reject($this->credentials, $e);

                throw $e;
            }
            $token = $this->auth->getAccessToken($this->credentials, true);

            try {
                return $this->transport->send($request->withBearer($token));
            } catch (AuthenticationException $again) {
                if ($again->statusCode() === 401) {
                    // A fresh token was rejected too: at most one re-login per request, then fail fast for a while.
                    $this->auth->reject($this->credentials, $again);
                }

                throw $again;
            }
        }
    }
}
