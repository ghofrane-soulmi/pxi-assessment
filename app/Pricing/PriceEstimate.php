<?php

namespace App\Pricing;

/** What the user could be charged for a generation, in nanos, before it runs. */
final readonly class PriceEstimate
{
    public function __construct(
        public int $minChargeNanos,
        public int $maxChargeNanos,
    ) {}
}
