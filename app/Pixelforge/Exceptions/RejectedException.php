<?php

namespace App\Pixelforge\Exceptions;

/**
 * Pixelforge refused this request for good (400 content_policy_violation,
 * 422 validation_failed). Nothing was created or billed; retrying won't help,
 * the user needs to change their input. The message is safe to show them.
 */
class RejectedException extends PixelforgeException {}
