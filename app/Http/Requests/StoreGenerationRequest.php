<?php

namespace App\Http\Requests;

use App\Pricing\PricingCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors Pixelforge's own limits, so the user sees errors before we submit anything. */
class StoreGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_CHARACTERS = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::in(self::sizes())],
            'quality' => ['required', Rule::in(array_keys(PricingCalculator::OUTPUT_TOKENS))],
            'reference_images' => ['present', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to :max reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }

    /** @return list<string> */
    public static function sizes(): array
    {
        return array_keys(PricingCalculator::OUTPUT_TOKENS['low']);
    }
}
