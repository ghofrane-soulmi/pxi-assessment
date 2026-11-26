<?php

use App\Support\Money;

it('formats nanos as exact dollars', function (int $nanos, string $expected) {
    expect(Money::format($nanos))->toBe($expected);
})->with([
    'zero' => [0, '$0.00'],
    'docs charge example' => [211_350_000, '$0.21135'],
    'all nine decimals' => [467_343_750, '$0.46734375'],
    'one nano' => [1, '$0.000000001'],
    'whole cents' => [1_500_000_000, '$1.50'],
    'whole dollars' => [12_000_000_000, '$12.00'],
    'negative (refund)' => [-5_000, '-$0.000005'],
]);
