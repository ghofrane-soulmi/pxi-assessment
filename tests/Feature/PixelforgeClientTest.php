<?php

use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\Exceptions\RetryLaterException;
use App\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new PixelforgeClient('https://pixelforge.test/v1', 'pf_test_key');
    $this->generation = Generation::factory()->make([
        'id' => '9b1e7c2a-0000-4000-8000-000000000001',
        'prompt' => 'a red fox',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => [],
    ]);
});

function errorResponse(int $status, string $code, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge says {$code}"]], $status, $headers);
}

describe('createGeneration', function () {
    it('sends the generation with our UUID as Idempotency-Key and metadata', function () {
        Http::fake(['pixelforge.test/*' => Http::response(['id' => 'gen_123', 'status' => 'queued'], 202)]);

        $result = $this->client->createGeneration($this->generation);

        expect($result)->toBe(['id' => 'gen_123', 'status' => 'queued']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', '9b1e7c2a-0000-4000-8000-000000000001')
            && $request->data() === [
                'prompt' => 'a red fox',
                'size' => '1536x1024',
                'quality' => 'high',
                'metadata' => ['generation_id' => '9b1e7c2a-0000-4000-8000-000000000001'],
            ]);
    });

    it('includes reference images only when there are some', function () {
        Http::fake(['pixelforge.test/*' => Http::response(['id' => 'gen_123'], 202)]);
        $this->generation->reference_images = ['https://example.com/a.png'];

        $this->client->createGeneration($this->generation);

        Http::assertSent(fn (Request $request) => $request['reference_images'] === ['https://example.com/a.png']);
    });

    it('asks to retry later on limits and overloads, using Retry-After', function (int $status, string $code, array $headers, int $wait) {
        Http::fake(['pixelforge.test/*' => errorResponse($status, $code, $headers)]);

        try {
            $this->client->createGeneration($this->generation);
            $this->fail('Expected RetryLaterException');
        } catch (RetryLaterException $e) {
            expect($e->errorCode)->toBe($code)
                ->and($e->httpStatus)->toBe($status)
                ->and($e->retryAfterSeconds)->toBe($wait);
        }
    })->with([
        'concurrency limit' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], 5],
        'rate limit' => [429, 'rate_limit_exceeded', ['Retry-After' => '12'], 12],
        'overloaded' => [503, 'overloaded', ['Retry-After' => '2'], 2],
        'server error without Retry-After' => [500, 'internal_error', [], 5],
    ]);

    it('asks to retry later when Pixelforge cannot be reached', function () {
        Http::fake(['pixelforge.test/*' => Http::failedConnection()]);

        $this->client->createGeneration($this->generation);
    })->throws(RetryLaterException::class);

    it('reports a permanent rejection the user must fix', function (int $status, string $code) {
        Http::fake(['pixelforge.test/*' => errorResponse($status, $code)]);

        try {
            $this->client->createGeneration($this->generation);
            $this->fail('Expected RejectedException');
        } catch (RejectedException $e) {
            expect($e->errorCode)->toBe($code)
                ->and($e->getMessage())->toBe("Pixelforge says {$code}");
        }
    })->with([
        [400, 'content_policy_violation'],
        [422, 'validation_failed'],
    ]);

    it('treats other errors as neither retryable nor the user\'s fault', function (int $status, string $code) {
        Http::fake(['pixelforge.test/*' => errorResponse($status, $code)]);

        try {
            $this->client->createGeneration($this->generation);
            $this->fail('Expected PixelforgeException');
        } catch (PixelforgeException $e) {
            expect($e)->not->toBeInstanceOf(RetryLaterException::class)
                ->and($e)->not->toBeInstanceOf(RejectedException::class)
                ->and($e->errorCode)->toBe($code);
        }
    })->with([
        [401, 'invalid_api_key'],
        [409, 'idempotency_key_reused'],
    ]);
});

it('fetches a generation by its Pixelforge id', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_123' => Http::response(['id' => 'gen_123', 'status' => 'succeeded'])]);

    expect($this->client->getGeneration('gen_123'))->toBe(['id' => 'gen_123', 'status' => 'succeeded']);
});

it('fetches the current rates', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response([
        'object' => 'pricing',
        'rates' => ['input_text_tokens' => '5.00', 'output_tokens' => '40.00'],
    ])]);

    expect($this->client->rates())->toBe(['input_text_tokens' => '5.00', 'output_tokens' => '40.00']);
});

it('is configured from services.pixelforge', function () {
    config(['services.pixelforge.base_url' => 'https://configured.test/v1', 'services.pixelforge.api_key' => 'pf_from_config']);
    app()->forgetInstance(PixelforgeClient::class);
    Http::fake(['configured.test/*' => Http::response(['rates' => []])]);

    app(PixelforgeClient::class)->rates();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://configured.test/v1/pricing')
        && $request->hasHeader('Authorization', 'Bearer pf_from_config'));
});
