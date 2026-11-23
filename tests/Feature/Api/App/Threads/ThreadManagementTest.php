<?php

use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadStar;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Tests\Support\ContractFixture;

function managedThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, ...$state]);

    return [$thread, $user, phoneHeadersFor($user)];
}

it('stars and unstars a thread, idempotently, and answers with its list row', function () {
    [$thread, $user, $headers] = managedThread();

    $first = $this->postJson(appThreadPath($thread, '/star'), ['starred' => true], $headers)->assertOk()
        ->assertJsonPath('id', $thread->public_id)->assertJsonPath('type', 'thread')->assertJsonPath('isStarred', true)
        ->assertJsonPath('source.key', 'dashboard')->assertJsonPath('source.label', 'Dashboard');
    $this->postJson(appThreadPath($thread, '/star'), ['starred' => true], $headers)->assertOk()->assertJsonPath('isStarred', true);
    expect(ThreadStar::query()->where('thread_id', $thread->id)->count())->toBe(1);
    ContractFixture::assertMatches('app', 'thread-star', $first->json(), ['id', 'lastActivityAt']);

    $this->postJson(appThreadPath($thread, '/star'), ['starred' => false], $headers)->assertOk()->assertJsonPath('isStarred', false);
    $this->postJson(appThreadPath($thread, '/star'), ['starred' => false], $headers)->assertOk()->assertJsonPath('isStarred', false);
    expect(ThreadStar::query()->count())->toBe(0);
});

it('lets a share recipient star but not close or delete', function () {
    [$thread, $owner] = managedThread(['visibility' => 'private', 'owner_user_id' => null]);
    $thread->update(['owner_user_id' => $owner->id]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $headers = phoneHeadersFor($recipient);

    $this->postJson(appThreadPath($thread, '/star'), ['starred' => true], $headers)->assertOk()->assertJsonPath('isStarred', true);
    $this->postJson(appThreadPath($thread, '/close'), [], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->deleteJson(appThreadPath($thread), [], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    expect($thread->fresh()->status)->toBe(ThreadStatus::Open);
});

it('rejects a star without a boolean', function (mixed $body) {
    [$thread, , $headers] = managedThread();

    $this->postJson(appThreadPath($thread, '/star'), $body, $headers)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['starred']]]);
})->with([[[]], [['starred' => 'maybe']]]);

it('closes a thread, idempotently, and answers with its list row', function () {
    [$thread, , $headers] = managedThread();

    $response = $this->postJson(appThreadPath($thread, '/close'), [], $headers)->assertOk()
        ->assertJsonPath('id', $thread->public_id)->assertJsonPath('status', 'closed');
    $this->postJson(appThreadPath($thread, '/close'), [], $headers)->assertOk()->assertJsonPath('status', 'closed');

    expect($thread->fresh()->status)->toBe(ThreadStatus::Closed)->and($thread->fresh()->close_reason)->toBe('manual');
    ContractFixture::assertMatches('app', 'thread-close', $response->json(), ['id', 'lastActivityAt']);
});

it('deletes a thread and then answers 404 for it', function () {
    [$thread, , $headers] = managedThread();
    $child = Thread::factory()->child($thread)->create();

    $response = $this->deleteJson(appThreadPath($thread), [], $headers)->assertOk()->assertJsonPath('status', 'deleted')->assertJsonPath('id', $thread->public_id);

    expect(Thread::query()->withoutGlobalScopes()->whereIn('id', [$thread->id, $child->id])->count())->toBe(0);
    $this->getJson(appThreadPath($thread), $headers)->assertNotFound();
    $this->deleteJson(appThreadPath($thread), [], $headers)->assertNotFound();
    $this->postJson(appThreadPath($thread, '/close'), [], $headers)->assertNotFound();
    ContractFixture::assertMatches('app', 'thread-deleted', $response->json(), ['id']);
});

it('answers another person on a private thread like a missing thread', function (string $method, string $suffix, array $body) {
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->json($method, '/api/app/v1/threads/zzzzzzzz' . $suffix, $body, $headers)->assertNotFound();
    $this->json($method, appThreadPath($thread, $suffix), $body, $headers)->assertNotFound()->assertExactJson($missing->json());
    expect($thread->fresh()->status)->toBe(ThreadStatus::Open)->and(ThreadStar::query()->count())->toBe(0);
})->with([
    'star' => ['POST', '/star', ['starred' => true]],
    'close' => ['POST', '/close', []],
    'delete' => ['DELETE', '', []],
]);

it('refuses without a token, with a revoked phone and with a computer token', function (string $method, string $suffix) {
    [$thread, $user] = managedThread();
    $path = appThreadPath($thread, $suffix);
    $body = $suffix === '/star' ? ['starred' => true] : [];

    $this->json($method, $path, $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->json($method, $path, $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->json($method, $path, $body, $computerHeaders)->assertUnauthorized();
    expect(Thread::query()->find($thread->id)->status)->toBe(ThreadStatus::Open);
})->with([['POST', '/star'], ['POST', '/close'], ['DELETE', '']]);
