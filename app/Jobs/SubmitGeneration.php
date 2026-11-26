<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\ApplyGenerationUpdate;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RejectedException;
use App\Pixelforge\Exceptions\RetryLaterException;
use App\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a pending generation to Pixelforge. Runs on the queue so the user gets an
 * instant response and so we can wait out rate limits without blocking a request.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Give up on unexpected errors (bugs) after a few tries; limits are handled by release(). */
    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    /** Keep retrying rate limits / overloads for at most 10 minutes after dispatch. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PixelforgeClient $client, ApplyGenerationUpdate $apply): void
    {
        // A webhook may have beaten us to it, or this job ran twice: nothing to do.
        if ($this->generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $remote = $client->createGeneration($this->generation);
        } catch (RetryLaterException $e) {
            // Nothing was created (or the same Idempotency-Key makes the retry safe).
            $this->release($e->retryAfterSeconds);

            return;
        } catch (RejectedException $e) {
            // e.g. content policy: the user must change the prompt. Shown to them as-is.
            $this->markFailed($apply, $e->errorCode, $e->getMessage());

            return;
        } catch (PixelforgeException $e) {
            // e.g. invalid API key: our problem, not the user's. Log it, keep the message generic.
            report($e);
            $this->markFailed($apply, $e->errorCode, 'Pixelforge could not process this request. You were not charged.');

            return;
        }

        $apply->apply($this->generation, $remote);
    }

    /** Called by the queue once the 10 minutes are up, or after repeated unexpected errors. */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            app(ApplyGenerationUpdate::class),
            'not_submitted',
            'Pixelforge is busy right now. Please try again in a few minutes. You were not charged.',
        );
    }

    /** Goes through the same locked, forward-only path as webhooks. */
    private function markFailed(ApplyGenerationUpdate $apply, ?string $code, string $message): void
    {
        $apply->apply($this->generation, [
            'status' => GenerationStatus::Failed->value,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
