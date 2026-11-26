<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends the home and dashboard pages to the generate form', function (string $url) {
    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertRedirect('/generations/create');
})->with(['/', '/dashboard']);

it('still asks guests to log in', function () {
    $this->followingRedirects()->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});
