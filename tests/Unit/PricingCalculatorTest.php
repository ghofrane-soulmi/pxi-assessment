<?php

use App\Pricing\PricingCalculator;

const RATES = [
    'input_text_tokens' => '5.00',
    'input_image_tokens' => '10.00',
    'output_tokens' => '40.00',
    'reasoning_tokens' => '12.50',
];

describe('actualCost', function () {
    it('matches the usage example in the Pixelforge docs ($0.169080000)', function () {
        $usage = [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ];

        expect(PricingCalculator::actualCost($usage, RATES))->toBe(169_080_000);
    });

    it('matches the worked maximum example in the docs ($0.373875000)', function () {
        $usage = [
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ];

        expect(PricingCalculator::actualCost($usage, RATES))->toBe(373_875_000);
    });

    it('prices a single token at its exact rate', function () {
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];

        expect(PricingCalculator::actualCost($usage, RATES))->toBe(5_000); // $0.000005
    });

    it('uses the rates fixed on the generation, not today\'s rates', function () {
        $usage = ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 1000, 'reasoning_tokens' => 0];

        expect(PricingCalculator::actualCost($usage, [...RATES, 'output_tokens' => '20.00']))->toBe(20_000_000);
    });

    it('refuses incomplete or invalid usage', function (array $usage) {
        PricingCalculator::actualCost($usage, RATES);
    })->throws(InvalidArgumentException::class)->with([
        'missing type' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0]],
        'negative' => [['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]],
        'string count' => [['input_text_tokens' => '41', 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]],
    ]);

    it('refuses a missing rate', function () {
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];
        $rates = RATES;
        unset($rates['reasoning_tokens']);

        PricingCalculator::actualCost($usage, $rates);
    })->throws(InvalidArgumentException::class);
});

describe('estimate', function () {
    it('matches the docs worked example at its maximum', function () {
        // high, 1536x1024, 400-character prompt, 2 reference images → max cost $0.373875000
        $estimate = PricingCalculator::estimate(str_repeat('a', 400), '1536x1024', 'high', 2, RATES);

        expect($estimate->maxChargeNanos)->toBe(PricingCalculator::chargeFor(373_875_000))
            ->and($estimate->maxChargeNanos)->toBe(467_343_750);
    });

    it('uses -15% text and minimum reasoning for the lower bound', function () {
        // text 100 → 85 tokens; images 1530; output 6450; reasoning 1000
        // cost = 85×5000 + 1530×10000 + 6450×40000 + 1000×12500 = 286_225_000
        $estimate = PricingCalculator::estimate(str_repeat('a', 400), '1536x1024', 'high', 2, RATES);

        expect($estimate->minChargeNanos)->toBe(357_781_250);
    });

    it('rounds the text bounds outwards', function () {
        // 40 chars → 10 tokens → min floor(8.5) = 8, max ceil(11.5) = 12
        // min cost = 8×5000 + 280×40000 + 0       = 11_240_000 → charge 14_050_000
        // max cost = 12×5000 + 280×40000 + 500×12500 = 17_510_000 → charge 21_887_500
        $estimate = PricingCalculator::estimate(str_repeat('a', 40), '1024x1024', 'low', 0, RATES);

        expect($estimate->minChargeNanos)->toBe(14_050_000)
            ->and($estimate->maxChargeNanos)->toBe(21_887_500);
    });

    it('rounds a partial text token up', function () {
        // 5 chars → ceil(5/4) = 2 tokens, max ceil(2.3) = 3; 4 chars → 1 token, max ceil(1.15) = 2
        $five = PricingCalculator::estimate('abcde', '1024x1024', 'low', 0, RATES);
        $four = PricingCalculator::estimate('abcd', '1024x1024', 'low', 0, RATES);

        expect($five->maxChargeNanos - $four->maxChargeNanos)->toBe(PricingCalculator::chargeFor(5_000));
    });

    it('counts characters, not bytes', function () {
        // "éééé" is 4 characters but 8 bytes: must estimate like "aaaa"
        expect(PricingCalculator::estimate('éééé', '1024x1024', 'low', 0, RATES))
            ->toEqual(PricingCalculator::estimate('aaaa', '1024x1024', 'low', 0, RATES));
    });

    it('always contains the real charge of a generation with the same inputs', function () {
        // A real result inside the documented ranges: 400 chars → actual text 110, reasoning 5000
        $actual = PricingCalculator::chargeFor(PricingCalculator::actualCost([
            'input_text_tokens' => 110,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 5000,
        ], RATES));

        $estimate = PricingCalculator::estimate(str_repeat('a', 400), '1536x1024', 'high', 2, RATES);

        expect($actual)->toBeGreaterThanOrEqual($estimate->minChargeNanos)
            ->toBeLessThanOrEqual($estimate->maxChargeNanos);
    });

    it('refuses unknown sizes or qualities', function (string $size, string $quality) {
        PricingCalculator::estimate('a cat', $size, $quality, 0, RATES);
    })->throws(InvalidArgumentException::class)->with([
        ['512x512', 'low'],
        ['1024x1024', 'ultra'],
    ]);
});

describe('chargeFor', function () {
    it('adds exactly 25% when the cost divides evenly', function (int $cost, int $charge) {
        expect(PricingCalculator::chargeFor($cost))->toBe($charge);
    })->with([
        'docs usage example' => [169_080_000, 211_350_000],
        'docs worked maximum' => [373_875_000, 467_343_750],
        'one text token' => [5_000, 6_250],
        'four nanos' => [4, 5],
    ]);

    it('charges nothing for nothing (failed generations)', function () {
        expect(PricingCalculator::chargeFor(0))->toBe(0);
    });

    it('rounds up to the next nano when 25% is not whole', function (int $cost, int $charge) {
        expect(PricingCalculator::chargeFor($cost))->toBe($charge);
    })->with([
        '1 → 1.25' => [1, 2],
        '2 → 2.5' => [2, 3],
        '3 → 3.75' => [3, 4],
    ]);

    it('refuses a negative cost', function () {
        PricingCalculator::chargeFor(-1);
    })->throws(InvalidArgumentException::class);
});

describe('nanosPerToken', function () {
    it('converts the published rates exactly', function (string $rate, int $expected) {
        expect(PricingCalculator::nanosPerToken($rate))->toBe($expected);
    })->with([
        'input text' => ['5.00', 5_000],
        'input image' => ['10.00', 10_000],
        'output' => ['40.00', 40_000],
        'reasoning' => ['12.50', 12_500],
        'no decimals' => ['3', 3_000],
        'smallest whole-nano rate' => ['0.001', 1],
    ]);

    it('refuses a rate that would need fractional nanos', function () {
        PricingCalculator::nanosPerToken('12.5005');
    })->throws(InvalidArgumentException::class);

    it('refuses malformed rates', function (string $rate) {
        PricingCalculator::nanosPerToken($rate);
    })->throws(InvalidArgumentException::class)->with(['', '-1.00', '1e3', 'abc', '1.']);
});
