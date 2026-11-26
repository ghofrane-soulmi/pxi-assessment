<?php

namespace App\Pixelforge;

use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\Exceptions\RetryLaterException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge HTTP API. It does not retry by itself:
 * callers (queued jobs) decide when to try again, using RetryLaterException.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * Submit a generation. Our generation's UUID is the Idempotency-Key, so sending
     * the same generation twice (e.g. after a timeout) returns the original instead
     * of creating and billing a second one.
     *
     * @return array<string, mixed> the Pixelforge generation object
     */
    public function createGeneration(Generation $generation): array
    {
        $body = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            // Lets a webhook find our row even if it arrives before this call returns.
            'metadata' => ['generation_id' => $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $body['reference_images'] = $generation->reference_images;
        }

        return $this->send(fn (PendingRequest $http) => $http
            ->withHeader('Idempotency-Key', $generation->id)
            ->post('/generations', $body));
    }

    /** @return array<string, mixed> the Pixelforge generation object */
    public function getGeneration(string $pixelforgeId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($pixelforgeId)));
    }

    /** @return array<string, string> current rates, USD per 1M tokens, keyed by token type */
    public function rates(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/pricing'))['rates'];
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        $http = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            // Timeout or network error: we don't know if Pixelforge received it.
            // Safe to retry because of the Idempotency-Key.
            throw new RetryLaterException(null, 'Could not reach Pixelforge: '.$e->getMessage(), null, 5);
        }

        if ($response->successful()) {
            return $response->json();
        }

        throw $this->toException($response);
    }

    private function toException(Response $response): PixelforgeException
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $message = $response->json('error.message') ?? "Pixelforge returned HTTP {$status}";

        if ($status === 429 || $status >= 500) {
            // Retry-After is in seconds; fall back to a short wait if it's missing.
            $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 5));

            return new RetryLaterException($code, $message, $status, $retryAfter);
        }

        if ($status === 400 || $status === 422) {
            return new RejectedException($code, $message, $status);
        }

        return new PixelforgeException($code, $message, $status);
    }
}
