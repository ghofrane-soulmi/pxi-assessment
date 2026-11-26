<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * All money is integer nano-dollars (1 USD = 1_000_000_000 nanos).
 * Pixelforge reports costs with 9 decimals and never rounds, so nanos are exact.
 */
final class PricingCalculator
{
    /** The four token types Pixelforge bills, as named in `usage` and `rates`. */
    public const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /** Token rules from the Pixelforge docs (also served by GET /v1/pricing). */
    public const CHARACTERS_PER_TEXT_TOKEN = 4;

    public const TEXT_TOKEN_VARIANCE_PERCENT = 15;

    public const TOKENS_PER_REFERENCE_IMAGE = 765;

    /** Output tokens are exact, by quality then size. */
    public const OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ];

    /** Reasoning tokens are only known after completion; [min, max] by quality. */
    public const REASONING_TOKENS = [
        'low' => [0, 500],
        'medium' => [200, 2000],
        'high' => [1000, 8000],
    ];

    /**
     * The range the user could be charged, before submitting.
     *
     * Exact up front: output tokens (size + quality) and image tokens (765 per reference).
     * Ranges: text tokens are ceil(chars / 4) ± 15%; reasoning tokens depend on quality.
     * Each bound is rounded outwards so the real charge always falls inside the range.
     *
     * @param  array<string, string>  $rates  current rates (USD per 1M tokens)
     */
    public static function estimate(string $prompt, string $size, string $quality, int $referenceImageCount, array $rates): PriceEstimate
    {
        // Characters, not bytes: "é" is one character.
        return self::estimateForCharacters(mb_strlen($prompt), $size, $quality, $referenceImageCount, $rates);
    }

    /**
     * Same as estimate(), from the prompt's length (lets the form ask for a live price
     * without sending the whole prompt on every keystroke).
     *
     * @param  array<string, string>  $rates  current rates (USD per 1M tokens)
     */
    public static function estimateForCharacters(int $promptCharacters, string $size, string $quality, int $referenceImageCount, array $rates): PriceEstimate
    {
        $outputTokens = self::OUTPUT_TOKENS[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size: {$quality} {$size}");
        [$minReasoning, $maxReasoning] = self::REASONING_TOKENS[$quality];

        if ($referenceImageCount < 0 || $promptCharacters < 0) {
            throw new InvalidArgumentException('Counts cannot be negative');
        }

        // ceil(a / b) in integers is intdiv(a + b - 1, b).
        $textEstimate = intdiv($promptCharacters + self::CHARACTERS_PER_TEXT_TOKEN - 1, self::CHARACTERS_PER_TEXT_TOKEN);
        $minText = intdiv($textEstimate * (100 - self::TEXT_TOKEN_VARIANCE_PERCENT), 100);       // round down
        $maxText = intdiv($textEstimate * (100 + self::TEXT_TOKEN_VARIANCE_PERCENT) + 99, 100);  // round up

        $imageTokens = $referenceImageCount * self::TOKENS_PER_REFERENCE_IMAGE;

        $minCost = self::actualCost([
            'input_text_tokens' => $minText,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $minReasoning,
        ], $rates);

        $maxCost = self::actualCost([
            'input_text_tokens' => $maxText,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $maxReasoning,
        ], $rates);

        return new PriceEstimate(self::chargeFor($minCost), self::chargeFor($maxCost));
    }

    /** Users pay Pixelforge's cost plus this markup. */
    public const MARKUP_PERCENT = 25;

    /**
     * What we charge the user for a given provider cost, in nanos.
     *
     * cost × 125 / 100, rounded UP to the next whole nano when it doesn't divide exactly,
     * so we never under-charge. With today's rates (all multiples of 500 nanos per token)
     * it always divides exactly; the rounding is only a guard for future rates.
     */
    public static function chargeFor(int $providerCostNanos): int
    {
        if ($providerCostNanos < 0) {
            throw new InvalidArgumentException('Provider cost cannot be negative');
        }

        $numerator = $providerCostNanos * (100 + self::MARKUP_PERCENT);

        // Integer ceiling division: intdiv rounds down, so add (divisor - 1) first.
        return intdiv($numerator + 99, 100);
    }

    /**
     * Provider cost of a finished generation, in nanos.
     *
     * @param  array<string, int>  $usage  the generation's `usage` (token counts)
     * @param  array<string, string>  $rates  the generation's `rates` (USD per 1M tokens), fixed at creation
     */
    public static function actualCost(array $usage, array $rates): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $tokens = $usage[$type] ?? null;
            $rate = $rates[$type] ?? null;

            if (! is_int($tokens) || $tokens < 0) {
                throw new InvalidArgumentException("Missing or invalid usage for {$type}");
            }
            if (! is_string($rate)) {
                throw new InvalidArgumentException("Missing rate for {$type}");
            }

            $total += $tokens * self::nanosPerToken($rate);
        }

        return $total;
    }

    /**
     * Convert a Pixelforge rate ("12.50" USD per 1M tokens) to nanos per token.
     *
     * USD per 1M tokens × 1e9 nanos/USD ÷ 1e6 tokens = rate × 1000 nanos per token.
     */
    public static function nanosPerToken(string $usdPerMillionTokens): int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $usdPerMillionTokens)) {
            throw new InvalidArgumentException("Invalid rate: {$usdPerMillionTokens}");
        }

        $nanos = bcmul($usdPerMillionTokens, '1000', 3);

        if (! str_ends_with($nanos, '.000')) {
            // e.g. "12.5005" would be 12500.5 nanos per token: refuse rather than round silently.
            throw new InvalidArgumentException("Rate too precise for whole nanos per token: {$usdPerMillionTokens}");
        }

        return (int) bcadd($nanos, '0', 0);
    }
}
