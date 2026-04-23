<?php

use App\Models\User;

beforeEach(function () {
    config()->set('yak.deployments.hostname_suffix', 'yak.example.com');
});

it('sends already-authed users straight to the preview URL', function () {
    $this->actingAs(User::factory()->create())
        ->get('/deployments/auth-bounce?to=' . urlencode('https://foo.yak.example.com/path'))
        ->assertRedirect('https://foo.yak.example.com/path');
});

it('stores url.intended and redirects anonymous users to login', function () {
    $response = $this->get('/deployments/auth-bounce?to=' . urlencode('https://foo.yak.example.com/path'));

    $response->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe('https://foo.yak.example.com/path');
});

it('rejects a bounce target that is not on the configured preview suffix', function () {
    $this->get('/deployments/auth-bounce?to=' . urlencode('https://evil.example.net/foo'))
        ->assertStatus(400);
});

it('rejects non-https targets', function () {
    $this->get('/deployments/auth-bounce?to=' . urlencode('http://foo.yak.example.com/path'))
        ->assertStatus(400);
});

it('rejects an empty target', function () {
    $this->get('/deployments/auth-bounce')
        ->assertStatus(400);
});

it('rejects the apex dashboard host itself (no bounce needed there)', function () {
    $this->get('/deployments/auth-bounce?to=' . urlencode('https://yak.example.com/tasks'))
        ->assertStatus(400);
});
