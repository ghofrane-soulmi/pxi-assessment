<?php

use App\Enums\GenerationStatus as S;

it('moves forward through the lifecycle', function (S $from, S $to) {
    expect($from->canMoveTo($to))->toBeTrue();
})->with([
    [S::Pending, S::Queued],
    [S::Queued, S::Processing],
    [S::Processing, S::Succeeded],
    [S::Processing, S::Failed],
    'skips a lost "processing" event' => [S::Queued, S::Succeeded],
    'rejected at submission' => [S::Pending, S::Failed],
    'webhook beats the POST response' => [S::Pending, S::Processing],
]);

it('never moves backwards or stays put', function (S $from, S $to) {
    expect($from->canMoveTo($to))->toBeFalse();
})->with([
    [S::Processing, S::Queued],
    [S::Queued, S::Queued],
    [S::Processing, S::Pending],
]);

it('never leaves a final status', function (S $final, S $to) {
    expect($final->canMoveTo($to))->toBeFalse();
})->with([
    'late processing after success' => [S::Succeeded, S::Processing],
    'success then failure' => [S::Succeeded, S::Failed],
    'failure then success' => [S::Failed, S::Succeeded],
    'duplicate success' => [S::Succeeded, S::Succeeded],
]);
