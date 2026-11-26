<?php

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Support\Facades\Cache;

/**
 * Current Pixelforge rates, for estimates only (actual costs always use the rates
 * fixed on each generation). Cached so the form doesn't call Pixelforge on every
 * keystroke or count against its 60 requests/minute limit.
 */
class PixelforgeRates
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return array<string, string>
     *
     * @throws PixelforgeException when Pixelforge is unreachable and nothing is cached
     */
    public function current(): array
    {
        return Cache::remember('pixelforge.rates', now()->addHour(), fn () => $this->client->rates());
    }
}
