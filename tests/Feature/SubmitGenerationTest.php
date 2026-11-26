<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\ApplyGenerationUpdate;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    $this->generation = Generation::factory()->create()->fresh();
});

function runSubmitJob(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationUpdate::class));
    $generation->refresh();

    return $job;
}

function pixelforgeError(int $status, string $code, array $headers = []): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge says {$code}"]], $status, $headers);
}

it('submits the generation and records that Pixelforge queued it', function () {
    Http::fake(['pixelforge.test/*' => Http::response(
        pixelforgeGeneration(['status' => 'queued', 'usage' => null, 'completed_at' => null]), 202,
    )]);

    runSubmitJob($this->generation)->assertNotReleased();

    expect($this->generation->status)->toBe(GenerationStatus::Queued)
        ->and($this->generation->pixelforge_id)->toBe('gen_123');
});

it('handles an idempotent replay that has already finished', function () {
    Http::fake(['pixelforge.test/*' => Http::response(pixelforgeGeneration(), 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmitJob($this->generation);

    expect($this->generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charge_nanos)->toBe(211_350_000);
});

it('waits and retries on limits, overloads and network errors', function ($response, int $delay) {
    Http::fake(['pixelforge.test/*' => $response]);

    runSubmitJob($this->generation)->assertReleased(delay: $delay);

    expect($this->generation->status)->toBe(GenerationStatus::Pending);
})->with([
    'concurrency limit' => fn () => [pixelforgeError(429, 'concurrency_limit_exceeded', ['Retry-After' => '5']), 5],
    'overloaded' => fn () => [pixelforgeError(503, 'overloaded', ['Retry-After' => '2']), 2],
    'unreachable' => fn () => [Http::failedConnection(), 5],
]);

it('fails without charge when Pixelforge rejects the prompt, showing its reason', function () {
    Http::fake(['pixelforge.test/*' => pixelforgeError(400, 'content_policy_violation')]);

    runSubmitJob($this->generation)->assertNotReleased();

    expect($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->error_code)->toBe('content_policy_violation')
        ->and($this->generation->error_message)->toBe('Pixelforge says content_policy_violation')
        ->and($this->generation->charge_nanos)->toBe(0);
});

it('fails with a generic message when the problem is on our side', function () {
    Http::fake(['pixelforge.test/*' => pixelforgeError(401, 'invalid_api_key')]);

    runSubmitJob($this->generation);

    expect($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->error_code)->toBe('invalid_api_key')
        ->and($this->generation->error_message)->toBe('Pixelforge could not process this request. You were not charged.');
});

it('does nothing if the generation was already accepted', function () {
    Http::fake();
    $this->generation->forceFill(['status' => GenerationStatus::Queued])->save();

    runSubmitJob($this->generation);

    Http::assertNothingSent();
});

it('fails without charge once the retry window is over', function () {
    (new SubmitGeneration($this->generation))->failed(new MaxAttemptsExceededException('timed out'));

    $this->generation->refresh();
    expect($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->error_code)->toBe('not_submitted')
        ->and($this->generation->charge_nanos)->toBe(0);
});

it('does not fail a generation that was accepted in the meantime', function () {
    $this->generation->forceFill(['status' => GenerationStatus::Succeeded])->save();

    (new SubmitGeneration($this->generation))->failed(null);

    expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('gives up retrying after 10 minutes', function () {
    $this->travelTo(now());

    expect((new SubmitGeneration($this->generation))->retryUntil()->getTimestamp())
        ->toBe(now()->addMinutes(10)->getTimestamp());
});
