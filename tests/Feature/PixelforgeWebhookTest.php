<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET]);
    $this->generation = Generation::factory()->create()->fresh();
});

/** A Pixelforge event about our generation. */
function webhookEvent(Generation $generation, string $type, array $object = [], string $eventId = 'evt_1'): array
{
    $status = str_replace('generation.', '', $type);

    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-26T12:00:30Z',
        'data' => ['object' => pixelforgeGeneration([
            'status' => $status,
            'metadata' => ['generation_id' => $generation->id],
            ...($status === 'succeeded' ? [] : ['usage' => null, 'image_url' => null]),
            ...$object,
        ])],
    ];
}

/** POST the raw JSON body with a Pixelforge-style signature header. */
function sendWebhook(array $event, ?string $signatureHeader = null, ?string $rawBody = null): TestResponse
{
    $body = $rawBody ?? json_encode($event);
    $t = time();
    $signatureHeader ??= "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", WEBHOOK_SECRET);

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signatureHeader,
    ], content: $body);
}

describe('signature', function () {
    it('accepts a correctly signed event', function () {
        sendWebhook(webhookEvent($this->generation, 'generation.succeeded'))->assertOk();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('rejects a bad, missing, stale or wrong-secret signature without touching anything', function (string $case) {
        $event = webhookEvent($this->generation, 'generation.succeeded');
        $body = json_encode($event);
        $now = time();
        $sixMinutesAgo = $now - 360;

        $header = match ($case) {
            'garbage' => "t={$now},v1=deadbeef",
            'missing' => '',
            'stale' => "t={$sixMinutesAgo},v1=".hash_hmac('sha256', "{$sixMinutesAgo}.{$body}", WEBHOOK_SECRET),
            'other secret' => "t={$now},v1=".hash_hmac('sha256', "{$now}.{$body}", 'whsec_someone_else'),
        };

        sendWebhook($event, $header)->assertUnauthorized();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Pending)
            ->and(DB::table('webhook_events')->count())->toBe(0);
    })->with(['garbage', 'missing', 'stale', 'other secret']);

    it('rejects a body altered after signing', function () {
        $event = webhookEvent($this->generation, 'generation.succeeded');
        $t = time();
        $header = "t={$t},v1=".hash_hmac('sha256', "{$t}.".json_encode($event), WEBHOOK_SECRET);

        $tampered = $event;
        $tampered['data']['object']['usage']['output_tokens'] = 1;

        sendWebhook($tampered, $header)->assertUnauthorized();
    });

    it('accepts any matching v1 among several (secret rotation)', function () {
        $event = webhookEvent($this->generation, 'generation.processing');
        $body = json_encode($event);
        $t = time();
        $good = hash_hmac('sha256', "{$t}.{$body}", WEBHOOK_SECRET);

        sendWebhook($event, "t={$t},v1=deadbeef,v1={$good}")->assertOk();
    });

    it('refuses everything when no secret is configured', function () {
        config(['services.pixelforge.webhook_secret' => null]);

        sendWebhook(webhookEvent($this->generation, 'generation.succeeded'))->assertUnauthorized();
    });
});

describe('delivery', function () {
    it('records cost exactly once when an event is delivered twice', function () {
        $event = webhookEvent($this->generation, 'generation.succeeded', eventId: 'evt_dup');

        sendWebhook($event)->assertOk();
        sendWebhook($event)->assertOk();

        expect($this->generation->refresh()->charge_nanos)->toBe(211_350_000)
            ->and(DB::table('webhook_events')->where('event_id', 'evt_dup')->count())->toBe(1);
    });

    it('ignores a "processing" event that arrives after "succeeded"', function () {
        sendWebhook(webhookEvent($this->generation, 'generation.succeeded', eventId: 'evt_2'))->assertOk();
        sendWebhook(webhookEvent($this->generation, 'generation.processing', eventId: 'evt_1'))->assertOk();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and($this->generation->charge_nanos)->toBe(211_350_000);
    });

    it('handles a webhook that arrives before our POST got its response', function () {
        // Our row is still pending and doesn't know Pixelforge's id yet.
        expect($this->generation->pixelforge_id)->toBeNull();

        sendWebhook(webhookEvent($this->generation, 'generation.processing'))->assertOk();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Processing)
            ->and($this->generation->pixelforge_id)->toBe('gen_123');
    });

    it('records a failure at no cost', function () {
        sendWebhook(webhookEvent($this->generation, 'generation.failed', [
            'error' => ['code' => 'generation_failed', 'message' => 'No image could be generated.'],
        ]))->assertOk();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Failed)
            ->and($this->generation->charge_nanos)->toBe(0);
    });

    it('returns an error and forgets the event if it cannot be applied, so the retry is processed', function () {
        $broken = webhookEvent($this->generation, 'generation.succeeded', ['usage' => null], 'evt_broken');

        $this->withoutExceptionHandling();
        expect(fn () => sendWebhook($broken))->toThrow(UnexpectedValueException::class);

        expect(DB::table('webhook_events')->where('event_id', 'evt_broken')->exists())->toBeFalse()
            ->and($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('acknowledges events for generations that are not ours', function () {
        $event = webhookEvent($this->generation, 'generation.succeeded');
        $event['data']['object']['metadata'] = [];
        $event['data']['object']['id'] = 'gen_unknown';

        sendWebhook($event)->assertOk();

        expect($this->generation->refresh()->status)->toBe(GenerationStatus::Pending);
    });

    it('acknowledges a dashboard ping', function () {
        sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])->assertOk();
    });
});
