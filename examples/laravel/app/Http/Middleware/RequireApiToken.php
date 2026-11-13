<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal bearer-token guard for the demo API. Fail closed: no configured token (or a short one) means 503, not "open".
 * Replace with your real authentication (Sanctum, Passport, session + policies ...) before exposing anything.
 */
final class RequireApiToken
{
    private const MIN_LENGTH = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('example.api_token');
        if (!is_string($expected) || strlen($expected) < self::MIN_LENGTH) {
            return response()->json(['error' => 'EXAMPLE_API_TOKEN is not configured (min. ' . self::MIN_LENGTH . ' characters).'], 503);
        }
        $given = $request->bearerToken();
        if (!is_string($given) || !hash_equals($expected, $given)) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
