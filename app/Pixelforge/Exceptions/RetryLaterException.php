<?php

namespace App\Pixelforge\Exceptions;

/**
 * Temporary problem: rate/concurrency/daily limit (429), overload (503), other 5xx,
 * or no response at all. Nothing was created, so it is safe to try again later,
 * and retrying with the same Idempotency-Key can never create a duplicate.
 */
class RetryLaterException extends PixelforgeException
{
    public function __construct(
        ?string $errorCode,
        string $message,
        ?int $httpStatus,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($errorCode, $message, $httpStatus);
    }
}
