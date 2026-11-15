<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

function shareThread(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $other = User::factory()->create(['name' => 'Second person']);

    return [privateThreadOf($owner, ['title' => 'Retry failed uploads', 'repo' => 'example/api']), $owner, $other];
}

it('shows the sharing of a private thread to its owner', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $owner] = shareThread();

    $response = $this->getJson(appThreadPath($thread, '/share'), phoneHeadersFor($owner))->assertOk()
        ->assertJsonPath('mode', 'only_me');

    expect(collect($response->json('people'))->pluck('name')->all())->toBe(['Second person', 'Test person'])
        ->and($response->json('userIds'))->toBe([]);
    ContractFixture::assertMatches('app', 'thread-share', $response->json(), ['people.*.id', 'userIds.*']);
});

it('shares with chosen people', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $owner, $other] = shareThread();
    $this->getJson(appThreadPath($thread), phoneHeadersFor($other))->assertNotFound();

    $response = $this->putJson(appThreadPath($thread, '/share'), ['mode' => 'people', 'user_ids' => [$other->id]], phoneHeadersFor($owner))
        ->assertOk()->assertExactJson(['mode' => 'people', 'userIds' => [$other->id]]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($other))->assertOk();
    ContractFixture::assertMatches('app', 'thread-share-updated', $response->json(), ['userIds.*']);
});

it('validates the share', function (array $body) {
    [$thread, $owner] = shareThread();

    $this->putJson(appThreadPath($thread, '/share'), $body, phoneHeadersFor($owner))->assertUnprocessable();
})->with([
    'unknown mode' => [['mode' => 'everyone_on_earth']],
    'people without ids' => [['mode' => 'people']],
    'too many ids' => [['mode' => 'people', 'user_ids' => range(1, 201)]],
]);

it('refuses a person who is not the owner', function () {
    [$thread, $owner, $recipient] = shareThread();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $team = Thread::factory()->create(['owner_user_id' => $owner->id]);
    $headers = phoneHeadersFor($recipient);

    $this->getJson(appThreadPath($thread, '/share'), $headers)->assertForbidden();
    $this->putJson(appThreadPath($thread, '/share'), ['mode' => 'only_me'], $headers)->assertForbidden();
    $this->getJson(appThreadPath($team, '/share'), $headers)->assertForbidden();
    $this->putJson(appThreadPath($team, '/share'), ['mode' => 'only_me'], $headers)->assertForbidden();
});

it('answers a hidden thread like a missing one', function (string $method) {
    [$hidden] = shareThread();
    $headers = phoneHeadersFor(User::factory()->create());
    $body = $method === 'PUT' ? ['mode' => 'only_me'] : [];

    $missing = $this->json($method, '/api/app/v1/threads/zzzzzzzz/share', $body, $headers)->assertNotFound();
    $this->json($method, appThreadPath($hidden, '/share'), $body, $headers)->assertNotFound()->assertExactJson($missing->json());
})->with(['GET', 'PUT']);

it('refuses without a token, with a revoked phone and with a computer token', function (string $method) {
    [$thread, $owner] = shareThread();
    $path = appThreadPath($thread, '/share');

    $this->json($method, $path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->json($method, $path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $owner->id], isPhone: false);
    $this->json($method, $path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
})->with(['GET', 'PUT']);
