<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('preview renders successfully when content matches the fixture', function () {
    $this->postJson(route('prompts.preview', 'tasks-setup'), [
        'content' => 'Setup for {{ $repoName }}.',
        'fixture' => 0,
    ])->assertOk()->assertJson([
        'ok' => true,
        'body' => 'Setup for acme/billing.',
    ]);
});

test('preview reports a runtime error', function () {
    $this->postJson(route('prompts.preview', 'tasks-setup'), [
        'content' => '{{ $repoName->method() }}',
        'fixture' => 0,
    ])->assertOk()->assertJson(['ok' => false]);
});

test('preview rejects the php directive', function () {
    $this->postJson(route('prompts.preview', 'tasks-setup'), [
        'content' => '@php(1)',
        'fixture' => 0,
    ])->assertOk()->assertJson([
        'ok' => false,
        'error' => 'Directive @php is not allowed in prompts.',
    ]);
});

test('preview 404s for an unknown slug', function () {
    $this->postJson(route('prompts.preview', 'nope'), [
        'content' => 'x',
        'fixture' => 0,
    ])->assertNotFound();
});
