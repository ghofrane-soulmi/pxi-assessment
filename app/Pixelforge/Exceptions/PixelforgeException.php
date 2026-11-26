<?php

namespace App\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Pixelforge answered with an error we can't fix by retrying or by asking the user
 * to change their input (e.g. 401 invalid_api_key: our configuration is wrong).
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
