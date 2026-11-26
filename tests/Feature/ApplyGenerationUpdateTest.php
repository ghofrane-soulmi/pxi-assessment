<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\ApplyGenerationUpdate;

beforeEach(function () {
    $this->generation = Generation::factory()->create()->fresh();
    $this->apply = fn (array $remote) => app(ApplyGenerationUpdate::class)->apply($this->generation, $remote);
});

it('records the Pixelforge id, rates and status when accepted', function () {
    $changed = ($this->apply)(pixelforgeGeneration(['status' => 'queued', 'usage' => null, 'completed_at' => null]));

    expect($changed)->toBeTrue()
        ->and($this->generation->status)->toBe(GenerationStatus::Queued)
        ->and($this->generation->pixelforge_id)->toBe('gen_123')
        ->and($this->generation->rates['reasoning_tokens'])->toBe('12.50')
        ->and($this->generation->charge_nanos)->toBeNull();
});

it('records exact usage, cost and charge on success', function () {
    ($this->apply)(pixelforgeGeneration());

    expect($this->generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->reasoning_tokens)->toBe(2950)
        ->and($this->generation->provider_cost_nanos)->toBe(169_080_000)  // $0.169080000
        ->and($this->generation->charge_nanos)->toBe(211_350_000)         // + 25%
        ->and($this->generation->completed_at->toIso8601ZuluString())->toBe('2026-11-26T12:00:30Z');
});

it('charges nothing for a failed generation and keeps the reason', function () {
    ($this->apply)(pixelforgeGeneration([
        'status' => 'failed',
        'usage' => null,
        'image_url' => null,
        'error' => ['code' => 'generation_failed', 'message' => 'No image could be generated.'],
    ]));

    expect($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->provider_cost_nanos)->toBe(0)
        ->and($this->generation->charge_nanos)->toBe(0)
        ->and($this->generation->error_code)->toBe('generation_failed')
        ->and($this->generation->error_message)->toBe('No image could be generated.');
});

it('ignores a late "processing" update after success', function () {
    ($this->apply)(pixelforgeGeneration());

    $changed = ($this->apply)(pixelforgeGeneration(['status' => 'processing', 'usage' => null]));

    expect($changed)->toBeFalse()
        ->and($this->generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->generation->charge_nanos)->toBe(211_350_000);
});

it('never re-bills a duplicate success, even with different numbers', function () {
    ($this->apply)(pixelforgeGeneration());

    $changed = ($this->apply)(pixelforgeGeneration([
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1],
    ]));

    expect($changed)->toBeFalse()
        ->and($this->generation->provider_cost_nanos)->toBe(169_080_000);
});

it('never turns a failure into a success', function () {
    ($this->apply)(pixelforgeGeneration(['status' => 'failed', 'usage' => null, 'error' => ['code' => 'internal_error', 'message' => 'x']]));

    expect(($this->apply)(pixelforgeGeneration()))->toBeFalse()
        ->and($this->generation->status)->toBe(GenerationStatus::Failed)
        ->and($this->generation->charge_nanos)->toBe(0);
});

it('bills with the rates fixed on the generation', function () {
    ($this->apply)(pixelforgeGeneration([
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '20.00', 'reasoning_tokens' => '12.50'],
    ]));

    // 41×5000 + 3300×20000 + 2950×12500 = 205_000 + 66_000_000 + 36_875_000 = 103_080_000
    expect($this->generation->provider_cost_nanos)->toBe(103_080_000);
});

it('changes nothing when a success arrives without usage', function () {
    expect(fn () => ($this->apply)(pixelforgeGeneration(['usage' => null])))
        ->toThrow(UnexpectedValueException::class);

    $this->generation->refresh();
    expect($this->generation->status)->toBe(GenerationStatus::Pending)
        ->and($this->generation->pixelforge_id)->toBeNull()
        ->and($this->generation->charge_nanos)->toBeNull();
});

it('rejects an unknown status', function () {
    ($this->apply)(pixelforgeGeneration(['status' => 'cancelled']));
})->throws(UnexpectedValueException::class);
