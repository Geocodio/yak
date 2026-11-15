<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Services\Threads\ThreadEventWindow;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ContractFixture;
use Tests\Support\InMemoryThreadEventWindow;

beforeEach(function () {
    $this->window = new InMemoryThreadEventWindow;
    app()->instance(ThreadEventWindow::class, $this->window);
});

/**
 * A thread owned by the user with an entry at sequence 1 and a turn at sequence 2.
 */
function threadWithStoredEvents(User $user): Thread
{
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'event_sequence' => 2]);
    $now = CarbonImmutable::parse('2026-11-15 12:00:00');
    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'text', 'text' => 'Done']]], 'created_at' => $now]);
    ThreadEvent::query()->create(['thread_id' => $thread->id, 'sequence' => 2, 'type' => 'turn', 'payload' => ['turnId' => 1, 'state' => 'completed'], 'created_at' => $now]);

    return $thread;
}

it('catches up from the database when the window is empty', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $thread = threadWithStoredEvents($user);

    $response = $this->getJson(appThreadPath($thread, '/events?after=0'), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('source', 'database')
        ->assertJsonPath('latestSequence', 2)
        ->assertJsonPath('events.0.sequence', 1)
        ->assertJsonPath('events.1.type', 'turn')
        ->assertJsonPath('events.0.data.blocks.0.html', fn ($html) => str_contains($html, 'Done'));
    ContractFixture::assertMatches('app', 'thread-events', $response->json(), ['events.*.data.turnId']);
});

it('catches up from the window when it covers the range', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'event_sequence' => 3]);
    foreach ([1, 2, 3] as $sequence) {
        $this->window->push($thread->id, $sequence, 'entry', ['role' => 'user', 'text' => "m{$sequence}"], CarbonImmutable::parse('2026-11-15 12:00:00'));
    }

    $this->getJson(appThreadPath($thread, '/events?after=1&limit=1'), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('source', 'window')
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.sequence', 2)
        ->assertJsonPath('latestSequence', 3);
});

it('falls back to the database when Redis fails', function () {
    Exceptions::fake();
    $window = new class extends InMemoryThreadEventWindow
    {
        public function since(int $threadId, int $after, int $limit): ?array
        {
            throw new RedisException('down');
        }
    };
    app()->instance(ThreadEventWindow::class, $window);
    $user = User::factory()->create();
    $thread = threadWithStoredEvents($user);

    $this->getJson(appThreadPath($thread, '/events'), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('source', 'database')
        ->assertJsonCount(2, 'events');
    Exceptions::assertReported(RedisException::class);
});

it('returns an empty list for a thread with no events', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->getJson(appThreadPath($thread, '/events'), phoneHeadersFor($user))->assertOk()
        ->assertExactJson(['events' => [], 'latestSequence' => 0, 'source' => 'database']);
});

it('returns one event for a pointer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $thread = threadWithStoredEvents($user);
    $headers = phoneHeadersFor($user);

    $response = $this->getJson(appThreadPath($thread, '/events/1'), $headers)->assertOk()->assertJsonPath('sequence', 1);
    ContractFixture::assertMatches('app', 'thread-event', $response->json(), []);

    $this->getJson(appThreadPath($thread, '/events/9'), $headers)->assertNotFound()->assertJsonPath('error', 'not_found');
    $this->getJson(appThreadPath($thread, '/events/99999999999'), $headers)->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('validates after and limit', function (string $query, string $field) {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->getJson(appThreadPath($thread, "/events?{$query}"), phoneHeadersFor($user))->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([['after=-1', 'after'], ['limit=0', 'limit'], ['limit=501', 'limit'], ['after=abc', 'after']]);

it('allows 1200 event requests a minute and keeps the 240 limit elsewhere', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $headers = phoneHeadersFor($user);

    foreach (range(1, 240) as $attempt) {
        $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    }
    $this->getJson('/api/app/v1/threads', $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited');

    foreach (range(1, 241) as $attempt) {
        $this->getJson(appThreadPath($thread, '/events'), $headers)->assertOk();
    }
});

it('answers too many event requests with a designed 429', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);

    foreach (range(1, 1200) as $attempt) {
        RateLimiter::hit(md5('app-events' . 'events:' . $device->id));
    }

    $this->getJson(appThreadPath($thread, '/events'), $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
    $this->getJson(appThreadPath($thread, '/events/1'), $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
});

it('lets a share recipient read events and pointers', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $owner = User::factory()->create();
    $thread = threadWithStoredEvents($owner);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $headers = phoneHeadersFor($recipient);

    $this->getJson(appThreadPath($thread, '/events'), $headers)->assertOk()->assertJsonCount(2, 'events');
    $this->getJson(appThreadPath($thread, '/events/1'), $headers)->assertOk()->assertJsonPath('sequence', 1);
});

it('answers a hidden thread like a missing one', function () {
    $hidden = privateThreadOf(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());

    foreach (['/events', '/events/1'] as $suffix) {
        $missing = $this->getJson("/api/app/v1/threads/zzzzzzzz{$suffix}", $headers)->assertNotFound();
        $this->getJson(appThreadPath($hidden, $suffix), $headers)->assertNotFound()->assertExactJson($missing->json());
        $this->getJson("/api/app/v1/threads/{$hidden->id}{$suffix}", $headers)->assertNotFound();
    }
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->getJson(appThreadPath($thread, '/events'), ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson(appThreadPath($thread, '/events'), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson(appThreadPath($thread, '/events'), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
