<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Pixelforge\ApplyGenerationUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Pixelforge events (signature already verified by middleware).
 *
 * Delivery is at-least-once and unordered, so:
 * - a re-delivered event (same id) is recorded once and otherwise ignored;
 * - ApplyGenerationUpdate only moves statuses forward and bills once.
 * Any exception returns a 500, so Pixelforge retries the event later.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyGenerationUpdate $apply): JsonResponse
    {
        $event = $request->json()->all();
        $type = (string) ($event['type'] ?? '');

        if (! str_starts_with($type, 'generation.')) {
            return response()->json(['received' => true]); // e.g. "ping" from the dashboard
        }

        $remote = $event['data']['object'] ?? [];
        $generation = $this->findGeneration($remote);

        if ($generation === null) {
            // Not one of ours (e.g. created by hand with this key). Acknowledge so it isn't retried.
            Log::warning('Pixelforge webhook for unknown generation', ['event_id' => $event['id'] ?? null, 'generation' => $remote['id'] ?? null]);

            return response()->json(['received' => true]);
        }

        // One transaction: if applying fails, the event row is rolled back too,
        // so the retried delivery is processed instead of being seen as a duplicate.
        DB::transaction(function () use ($event, $type, $generation, $remote, $apply) {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => (string) $event['id'],
                'type' => $type,
                'payload' => json_encode($event),
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return; // already processed this exact event
            }

            $apply->apply($generation, $remote);
        });

        return response()->json(['received' => true]);
    }

    /**
     * Prefer our own id from metadata: a webhook can arrive before the POST response
     * that tells us Pixelforge's id.
     *
     * @param  array<string, mixed>  $remote
     */
    private function findGeneration(array $remote): ?Generation
    {
        $ourId = $remote['metadata']['generation_id'] ?? null;

        if (is_string($ourId) && Str::isUuid($ourId)) {
            return Generation::find($ourId);
        }

        return isset($remote['id']) ? Generation::firstWhere('pixelforge_id', $remote['id']) : null;
    }
}
