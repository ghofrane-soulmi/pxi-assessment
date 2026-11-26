<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook not signed by Pixelforge with our key's signing secret.
 *
 * Header: Pixelforge-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "{t}.{raw body}">
 */
class VerifyPixelforgeSignature
{
    /** Older signatures are rejected so a captured request can't be replayed later. */
    public const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            // Misconfiguration: refuse (Pixelforge will retry) and make it visible in the logs.
            Log::error('Pixelforge webhook received but PIXELFORGE_WEBHOOK_SECRET is not set');
            abort(401);
        }

        [$timestamp, $signatures] = $this->parseHeader((string) $request->header('Pixelforge-Signature'));

        if ($timestamp === null || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            abort(401, 'Invalid or expired signature timestamp');
        }

        // Sign the raw body exactly as received: re-encoding parsed JSON could change it.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) { // constant-time comparison
                return $next($request);
            }
        }

        abort(401, 'Invalid signature');
    }

    /**
     * "t=1700000000,v1=abc" → [1700000000, ['abc']]. Several v1 values are allowed
     * (e.g. during a secret rotation); any one matching is enough.
     *
     * @return array{0: ?int, 1: list<string>}
     */
    private function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
