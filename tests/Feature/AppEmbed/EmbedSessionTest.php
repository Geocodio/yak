<?php

use App\Enums\ThreadShareMode;
use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use App\Support\AppEmbedRoutes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{user: User, device: Device, thread: Thread, session: array<string, mixed>}
 */
function embedWorld(): array
{
    [$user, $device] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    return ['user' => $user, 'device' => $device, 'thread' => $thread, 'session' => embedSessionData($user, $device)];
}

beforeEach(fn () => $this->withoutVite());

it('opens the thread page, events and broadcasting auth', function (string $name, string $template, bool $isOk) {
    ['user' => $user, 'thread' => $thread, 'session' => $session] = embedWorld();
    $uri = str_replace('{thread}', $thread->public_id, $template);

    actingAsEmbed($session);
    $embed = $this->get($uri);

    expect($embed->status())->not->toBe(401)->not->toBe(419);

    if ($isOk) {
        $embed->assertOk();
    }

    if ($name === 'app.embed.threads.show') {
        return;
    }

    // The embed layer is transparent: the same request on a normal web session answers the same.
    Auth::forgetGuards();
    $this->flushSession()->flushHeaders();
    $web = $this->actingAs($user)->get($uri);

    expect($embed->status())->toBe($web->status());
})->with([
    'page' => ['app.embed.threads.show', '/app/embed/threads/{thread}', true],
    'events' => ['threads.events.index', '/threads/{thread}/events', true],
    'event' => ['threads.events.show', '/threads/{thread}/events/1', false],
    'local turns' => ['threads.local-turns', '/threads/{thread}/local-turns', false],
    'run log' => ['threads.runs.log', '/threads/{thread}/runs/1/log', false],
    'task log' => ['threads.tasks.log', '/threads/{thread}/tasks/zzzzzzzz/log', false],
    'repositories' => ['threads.repositories.progress', '/threads/{thread}/repositories', true],
    'candidates' => ['threads.co-owner.candidates', '/threads/{thread}/co-owner/candidates', false],
    'stack' => ['threads.stack.show', '/threads/{thread}/stack', true],
]);

it('renders the thread page for the phone user on the embed guard', function () {
    ['user' => $user, 'thread' => $thread, 'session' => $session] = embedWorld();

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $thread->public_id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Threads/Show')->where('auth.user.id', $user->id));

    expect(Auth::getDefaultDriver())->toBe('web');
});

/** The null broadcaster answers 200 to any channel, so these tests run on the real reverb signing path. */
function useRealBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
}

it('authorizes the own thread channels of an embed session', function (string $channelPrefix) {
    useRealBroadcaster();
    ['thread' => $thread, 'session' => $session] = embedWorld();
    $channel = $channelPrefix . $thread->public_id;

    actingAsEmbed($session);
    $response = $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk();

    expect($response->json('auth'))->toStartWith('test-key:');
})->with(['private-thread.', 'presence-thread-presence.']);

it('refuses a thread hidden from the embed user on its channels', function (string $channelPrefix) {
    useRealBroadcaster();
    ['session' => $session] = embedWorld();
    $hidden = privateThreadOf(User::factory()->create());

    actingAsEmbed($session);
    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channelPrefix . $hidden->public_id])->assertForbidden();
})->with(['private-thread.', 'presence-thread-presence.']);

it('runs card actions with drive rights', function (string $name, string $method, string $template, array $payload) {
    ['user' => $user, 'thread' => $thread, 'session' => $session] = embedWorld();
    $uri = str_replace('{thread}', $thread->public_id, $template);

    actingAsEmbed($session);
    $embed = $this->call($method, $uri, $payload);

    expect($embed->status())->not->toBe(404)->not->toBe(401)->not->toBe(403)->not->toBe(419);

    Auth::forgetGuards();
    $this->flushSession()->flushHeaders();
    expect($embed->status())->toBe($this->actingAs($user)->call($method, $uri, $payload)->status());
})->with([
    ['threads.clarification-answers.store', 'POST', '/threads/{thread}/clarification-answers', ['answers' => []]],
    ['threads.repository-answer.store', 'POST', '/threads/{thread}/repository-answer', []],
    ['threads.co-owner.approve', 'POST', '/threads/{thread}/co-owner/approve', []],
    ['threads.co-owner.request-changes', 'POST', '/threads/{thread}/co-owner/request-changes', ['feedback' => 'x']],
    ['threads.co-owner.reject', 'POST', '/threads/{thread}/co-owner/reject', []],
    ['threads.co-owner.withdraw', 'POST', '/threads/{thread}/co-owner/withdraw', []],
    ['threads.co-owner.check-access', 'POST', '/threads/{thread}/co-owner/check-access', []],
    ['threads.co-owner.decline', 'POST', '/threads/{thread}/co-owner/decline', []],
    ['threads.retry', 'POST', '/threads/{thread}/retry', []],
    ['threads.cancel', 'POST', '/threads/{thread}/cancel', []],
    ['threads.move', 'POST', '/threads/{thread}/move', ['repo' => 'nope']],
    ['threads.retry-render', 'POST', '/threads/{thread}/retry-render', []],
    ['threads.continue', 'POST', '/threads/{thread}/continue', []],
    ['threads.restore.retry', 'POST', '/threads/{thread}/restore/retry', []],
    ['threads.seen', 'POST', '/threads/{thread}/seen', []],
]);

it('refuses a card action to a share recipient who may only read', function () {
    ['thread' => $thread] = embedWorld();
    [$recipient, $device] = embedPhone();
    app(ThreadSharing::class)->apply($thread, $thread->creator ?? User::query()->find($thread->created_by_user_id), ThreadShareMode::People, [$recipient->id]);

    actingAsEmbed(embedSessionData($recipient, $device));
    $this->post('/threads/' . $thread->public_id . '/retry')->assertForbidden();
});

it('hides a private thread of someone else', function () {
    ['session' => $session] = embedWorld();
    $private = privateThreadOf(User::factory()->create());

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $private->public_id)->assertNotFound();

    actingAsEmbed($session);
    $this->get('/app/embed/threads/zzzzzzzz')->assertNotFound();
});

it('ends the session when the phone is revoked', function () {
    ['device' => $device, 'thread' => $thread, 'session' => $session] = embedWorld();
    $device->forceFill(['revoked_at' => now()])->save();

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();

    expect(session()->has('app_embed'))->toBeFalse()->and(session()->has(Auth::guard('app-embed')->getName()))->toBeFalse();
});

it('ends the session at the absolute expiry', function () {
    ['user' => $user, 'device' => $device, 'thread' => $thread] = embedWorld();
    $file = captureYakLog();

    actingAsEmbed(embedSessionData($user, $device, now()->addMinute()->getTimestamp()));
    $this->travel(2)->minutes();
    $this->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();

    expect(session()->has('app_embed'))->toBeFalse()->and((string) file_get_contents($file))->toContain('app_embed.ended')->toContain('"reason":"expired"');
});

it('ends the session when the user is deleted', function () {
    ['user' => $user, 'thread' => $thread, 'session' => $session] = embedWorld();
    Device::query()->where('user_id', $user->id)->delete();
    $user->delete();

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();

    expect(session()->has('app_embed'))->toBeFalse();
});

it('ends the session when the device belongs to another user', function () {
    ['thread' => $thread, 'session' => $session] = embedWorld();
    [, $otherDevice] = embedPhone();
    $session['app_embed']['device_id'] = $otherDevice->id;

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();

    expect(session()->has('app_embed'))->toBeFalse();
});

it('ends a session that lost its embed data', function () {
    ['thread' => $thread, 'session' => $session] = embedWorld();
    unset($session['app_embed']);

    actingAsEmbed($session);
    $this->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();

    expect(session()->has(Auth::guard('app-embed')->getName()))->toBeFalse();
});

it('answers an ended session with JSON for a fetch', function () {
    ['device' => $device, 'thread' => $thread, 'session' => $session] = embedWorld();
    $device->forceFill(['revoked_at' => now()])->save();

    actingAsEmbed($session);
    $this->getJson('/threads/' . $thread->public_id . '/events')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

it('answers 401 with no login redirect when nobody is signed in on the embed page', function () {
    $this->get('/app/embed/threads/d0e515ef')->assertUnauthorized();
});

it('cannot call the app or CLI API with the cookie', function (string $uri) {
    ['session' => $session] = embedWorld();

    actingAsEmbed($session);
    $response = $this->getJson($uri, ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    expect($response->headers->getCookies())->toBeEmpty();
})->with(['/api/app/v1/me', '/api/app/v1/threads', '/api/cli/v1/me']);

it('never lets a web cookie session through the embed page', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->get('/app/embed/threads/' . $thread->public_id)->assertUnauthorized();
});

it('rewrites a redirect to a thread into the embed thread', function (string $location, string $expected) {
    Route::middleware('web')->get('/embed-redirect-probe', fn () => redirect($location))->name('shared-files.show');
    ['session' => $session] = embedWorld();

    actingAsEmbed($session);
    $this->get('/embed-redirect-probe')->assertRedirect($expected);
})->with([
    'relative' => ['/threads/d0e515ef', 'http://localhost/app/embed/threads/d0e515ef'],
    'absolute' => ['http://localhost/threads/d0e515ef', 'http://localhost/app/embed/threads/d0e515ef'],
    'other host stays' => ['https://other.example.com/threads/d0e515ef', 'https://other.example.com/threads/d0e515ef'],
    'other path stays' => ['/threads/d0e515ef/files', 'http://localhost/threads/d0e515ef/files'],
]);

it('sends no-store and same-origin referrer', function () {
    ['thread' => $thread, 'session' => $session] = embedWorld();

    actingAsEmbed($session);
    $response = $this->get('/app/embed/threads/' . $thread->public_id)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin');
});

it('keeps the allow-list names real', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->all();

    expect(array_values(array_diff(AppEmbedRoutes::ALLOWED, $names)))->toBe([]);
});

it('blocks the sending of messages and the settings pages', function (string $method, string $uri) {
    ['thread' => $thread, 'session' => $session] = embedWorld();

    actingAsEmbed($session);
    $this->call($method, str_replace('{thread}', $thread->public_id, $uri))->assertNotFound();
})->with([
    ['POST', '/threads/{thread}/messages'],
    ['GET', '/threads/{thread}'],
    ['GET', '/settings/devices'],
    ['GET', '/login'],
    ['GET', '/auth/google'],
]);

it('lets a new hand-off replace a live embed session', function () {
    ['device' => $device, 'session' => $session] = embedWorld();
    $headers = phoneHeadersFor($device->user);
    $code = mintEmbedCode($headers);

    actingAsEmbed($session);
    $this->withHeader('User-Agent', EMBED_USER_AGENT)->post('http://localhost/app/embed/session', ['code' => $code])->assertStatus(303);
});

it('a normal web session still reaches settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.devices'))->assertOk();
    $this->actingAs($user)->get('/threads/' . Thread::factory()->create(['created_by_user_id' => $user->id])->public_id)->assertOk();
    expect(Auth::getDefaultDriver())->toBe('web');
});
