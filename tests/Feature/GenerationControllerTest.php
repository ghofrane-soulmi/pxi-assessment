<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    // Flip $this->pixelforgeDown to simulate an outage.
    $this->pixelforgeDown = false;
    Http::fake(fn () => $this->pixelforgeDown
        ? Http::response(['error' => ['code' => 'overloaded', 'message' => 'x']], 503)
        : Http::response(['rates' => pixelforgeGeneration()['rates']]));
    Queue::fake();
    $this->user = User::factory()->create();
});

function validGeneration(array $overrides = []): array
{
    return [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ...$overrides,
    ];
}

it('sends guests to the login page', function (string $method, string $route) {
    $this->call($method, route($route))->assertRedirect(route('login'));
})->with([
    ['GET', 'generations.index'],
    ['GET', 'generations.create'],
    ['POST', 'generations.store'],
]);

it('shows the generate form with the options Pixelforge supports', function () {
    $this->actingAs($this->user)->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('sizes.1', ['value' => '1536x1024', 'label' => 'Landscape'])
            ->where('maxReferenceImages', 4));
});

describe('estimate', function () {
    it('returns the price range from the shared calculator', function () {
        // The docs' worked example: max cost $0.373875 → charge $0.46734375
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', ['characters' => 400, 'size' => '1536x1024', 'quality' => 'high', 'references' => 2]))
            ->assertOk()
            ->assertExactJson(['min' => '$0.35778125', 'max' => '$0.46734375']);
    });

    it('caches Pixelforge rates instead of calling it on every keystroke', function () {
        foreach (range(1, 3) as $characters) {
            $this->actingAs($this->user)->getJson(route('generations.estimate', [
                'characters' => $characters, 'size' => '1024x1024', 'quality' => 'low', 'references' => 0,
            ]))->assertOk();
        }

        Http::assertSentCount(1);
    });

    it('says so when pricing is unavailable', function () {
        $this->pixelforgeDown = true;

        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', ['characters' => 10, 'size' => '1024x1024', 'quality' => 'low', 'references' => 0]))
            ->assertStatus(503);
    });

    it('validates its input', function () {
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', ['characters' => -1, 'size' => '512x512', 'quality' => 'ultra', 'references' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['characters', 'size', 'quality', 'references']);
    });
});

describe('store', function () {
    it('saves a pending generation with the server-side estimate and queues it', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), validGeneration())
            ->assertRedirect(route('generations.index'));

        $generation = Generation::sole();
        expect($generation->user_id)->toBe($this->user->id)
            ->and($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
            ->and($generation->min_charge_nanos)->toBe(357_781_250)
            ->and($generation->max_charge_nanos)->toBe(467_343_750)
            ->and($generation->charge_nanos)->toBeNull();

        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
    });

    it('rejects input Pixelforge would reject, before anything is created', function (array $input, string $field) {
        $this->actingAs($this->user)
            ->post(route('generations.store'), validGeneration($input))
            ->assertSessionHasErrors($field);

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'empty prompt' => [['prompt' => ''], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'unknown size' => [['size' => '512x512'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
        'not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
        'not http(s)' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    ]);

    it('accepts a generation without reference images', function () {
        $this->actingAs($this->user)
            ->post(route('generations.store'), validGeneration(['reference_images' => []]))
            ->assertSessionHasNoErrors();

        expect(Generation::sole()->reference_images)->toBe([]);
    });

    it('shows a friendly error when pricing is unavailable', function () {
        $this->pixelforgeDown = true;

        $this->actingAs($this->user)
            ->post(route('generations.store'), validGeneration())
            ->assertSessionHasErrors(['prompt' => 'Pricing is temporarily unavailable. Please try again in a moment.']);

        expect(Generation::count())->toBe(0);
    });
});

describe('index', function () {
    it('lists only the user\'s own generations, newest first, with exact amounts', function () {
        Generation::factory()->for($this->user)->create(['prompt' => 'older', 'created_at' => now()->subMinute()]);
        $newest = Generation::factory()->for($this->user)->create(['prompt' => 'newest']);
        $newest->forceFill(['status' => GenerationStatus::Succeeded, 'charge_nanos' => 211_350_000])->save();
        Generation::factory()->create(['prompt' => 'someone else']);

        $this->actingAs($this->user)->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 2)
                ->where('generations.data.0.prompt', 'newest')
                ->where('generations.data.0.status', 'succeeded')
                ->where('generations.data.0.charge', '$0.21135')
                ->where('generations.data.1.prompt', 'older')
                ->where('generations.data.1.charge', null));
    });
});
