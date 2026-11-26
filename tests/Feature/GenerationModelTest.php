<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

it('starts as pending with a UUID and no cost yet', function () {
    $generation = Generation::factory()->create()->fresh();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and(Str::isUuid($generation->id))->toBeTrue()
        ->and($generation->provider_cost_nanos)->toBeNull()
        ->and($generation->charge_nanos)->toBeNull();
});

it('stores nano-dollar amounts above the 32-bit integer limit', function () {
    // $3 = 3_000_000_000 nanos, more than a 32-bit int can hold (2_147_483_647)
    $generation = Generation::factory()->create(['max_charge_nanos' => 3_000_000_000])->fresh();

    expect($generation->max_charge_nanos)->toBe(3_000_000_000);
});

it('keeps the money record when the user deletes their account', function () {
    $generation = Generation::factory()->create(['max_charge_nanos' => 21_887_500]);

    $generation->user->delete();

    $kept = Generation::find($generation->id);
    expect($kept)->not->toBeNull()
        ->and($kept->user_id)->toBeNull()
        ->and($kept->max_charge_nanos)->toBe(21_887_500);
});

it('belongs to a user', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->count(2)->create();

    expect($user->generations)->toHaveCount(2);
});
