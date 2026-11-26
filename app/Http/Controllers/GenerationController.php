<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeRates;
use App\Pricing\PricingCalculator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const SIZE_LABELS = [
        '1024x1024' => 'Square',
        '1536x1024' => 'Landscape',
        '1024x1536' => 'Portrait',
    ];

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest()
            ->paginate(20)
            ->through(fn (Generation $generation) => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'status' => $generation->status->value,
                'min_charge' => Money::format($generation->min_charge_nanos),
                'max_charge' => Money::format($generation->max_charge_nanos),
                'charge' => $generation->charge_nanos === null ? null : Money::format($generation->charge_nanos),
                'error_message' => $generation->error_message,
                'created_at' => $generation->created_at->toIso8601String(),
            ]);

        return Inertia::render('generations/Index', ['generations' => $generations]);
    }

    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => collect(StoreGenerationRequest::sizes())
                ->map(fn (string $size) => ['value' => $size, 'label' => self::SIZE_LABELS[$size] ?? $size])
                ->values(),
            'qualities' => array_keys(PricingCalculator::OUTPUT_TOKENS),
            'maxPromptCharacters' => StoreGenerationRequest::MAX_PROMPT_CHARACTERS,
            'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
        ]);
    }

    /** Live price range for the form. Same calculator as store(), so the numbers can't drift. */
    public function estimate(Request $request, PixelforgeRates $rates): JsonResponse
    {
        $input = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::in(StoreGenerationRequest::sizes())],
            'quality' => ['required', Rule::in(array_keys(PricingCalculator::OUTPUT_TOKENS))],
            'references' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
        ]);

        try {
            $estimate = PricingCalculator::estimateForCharacters(
                (int) $input['characters'], $input['size'], $input['quality'], (int) $input['references'], $rates->current(),
            );
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Pricing is temporarily unavailable.'], 503);
        }

        return response()->json([
            'min' => Money::format($estimate->minChargeNanos),
            'max' => Money::format($estimate->maxChargeNanos),
        ]);
    }

    public function store(StoreGenerationRequest $request, PixelforgeRates $rates): RedirectResponse
    {
        $input = $request->validated();

        try {
            // Recomputed here: the price shown in the browser is never trusted.
            $estimate = PricingCalculator::estimate(
                $input['prompt'], $input['size'], $input['quality'], count($input['reference_images']), $rates->current(),
            );
        } catch (PixelforgeException) {
            throw ValidationException::withMessages(['prompt' => 'Pricing is temporarily unavailable. Please try again in a moment.']);
        }

        $generation = $request->user()->generations()->create([
            'prompt' => $input['prompt'],
            'size' => $input['size'],
            'quality' => $input['quality'],
            'reference_images' => array_values($input['reference_images']),
            'min_charge_nanos' => $estimate->minChargeNanos,
            'max_charge_nanos' => $estimate->maxChargeNanos,
        ]);

        SubmitGeneration::dispatch($generation);

        return to_route('generations.index');
    }
}
