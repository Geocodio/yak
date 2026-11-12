<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest sees the landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('welcome')
        ->assertSee('Beam it up to Yak')
        ->assertSee('Meets you where you are')
        ->assertSee('curl -fsSL ' . route('install') . ' | sh')
        ->assertSee(route('login'));
});

test('a person with no visible threads sees the welcome page with an empty checklist', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home/Welcome')
            ->where('welcome.hasRepository', false)
            ->where('welcome.hasClaudeAccount', false)
            ->where('repos', []));
});

test('a private thread owned by someone else does not count', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->private()->create();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Home/Welcome'));
});

test('home redirects to the most recently active visible thread', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->create(['last_activity_at' => now()->subDay()]);
    $latest = Thread::factory()->create(['last_activity_at' => now()]);
    Thread::factory()->private()->create(['last_activity_at' => now()->addHour()]);

    $this->get('/')->assertRedirect(route('threads.show', $latest));
});

test('a thread without activity falls back to its creation time', function () {
    $this->actingAs(User::factory()->create());
    Thread::factory()->create(['last_activity_at' => null, 'created_at' => now()->subDays(2)]);
    $newer = Thread::factory()->create(['last_activity_at' => null, 'created_at' => now()->subDay()]);

    $this->get('/')->assertRedirect(route('threads.show', $newer));
});

test('the checklist marks the repository and the Claude account done', function () {
    $this->actingAs(User::factory()->create());
    Repository::factory()->create(['is_active' => true]);
    ClaudeAccount::factory()->system()->create(['validated_at' => now()]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home/Welcome')
            ->where('welcome.hasRepository', true)
            ->where('welcome.hasClaudeAccount', true));
});

test('the checklist counts an enabled channel', function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@example.test']);
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('welcome.hasChannel', true));
});
