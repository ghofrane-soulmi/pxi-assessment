<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The single place where Pixelforge's view of a generation is written to ours.
 * Used by the submit job (POST response), webhooks and polling, so they can
 * all arrive in any order, any number of times, and the result is the same.
 */
class ApplyGenerationUpdate
{
    /**
     * @param  array<string, mixed>  $remote  a Pixelforge generation object
     * @return bool whether the status changed
     */
    public function apply(Generation $generation, array $remote): bool
    {
        $next = GenerationStatus::tryFrom($remote['status'] ?? '')
            ?? throw new UnexpectedValueException('Unknown Pixelforge status: '.json_encode($remote['status'] ?? null));

        $changed = DB::transaction(function () use ($generation, $remote, $next) {
            // Lock the row: a webhook, a poll and the submit job may race on the same
            // generation. The second one waits here, then sees the first one's result.
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            $locked->pixelforge_id ??= $remote['id'] ?? null;
            $locked->rates ??= $remote['rates'] ?? null;

            if (! $locked->status->canMoveTo($next)) {
                // Duplicate or out-of-order update: never move back, never re-bill.
                $locked->save();

                return false;
            }

            $locked->status = $next;

            if ($next === GenerationStatus::Succeeded) {
                $this->recordCost($locked, $remote);
            }

            if ($next === GenerationStatus::Failed) {
                $locked->error_code = $remote['error']['code'] ?? null;
                $locked->error_message = $remote['error']['message'] ?? null;
                // Failed generations are free. Zero (not null) so reports can sum them.
                $locked->provider_cost_nanos = 0;
                $locked->charge_nanos = 0;
            }

            if ($next->isFinal()) {
                $locked->completed_at = Carbon::parse($remote['completed_at'] ?? now());
            }

            $locked->save();

            return true;
        });

        $generation->refresh();

        return $changed;
    }

    /** @param  array<string, mixed>  $remote */
    private function recordCost(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'] ?? throw new UnexpectedValueException('Succeeded generation without usage');
        // The rates fixed on this generation by Pixelforge, not today's rates.
        $rates = $remote['rates'] ?? $generation->rates ?? throw new UnexpectedValueException('Succeeded generation without rates');

        // Throws on malformed usage, which rolls back the whole transaction.
        $cost = PricingCalculator::actualCost($usage, $rates);

        $generation->input_text_tokens = $usage['input_text_tokens'];
        $generation->input_image_tokens = $usage['input_image_tokens'];
        $generation->output_tokens = $usage['output_tokens'];
        $generation->reasoning_tokens = $usage['reasoning_tokens'];
        $generation->provider_cost_nanos = $cost;
        $generation->charge_nanos = PricingCalculator::chargeFor($cost);
    }
}
