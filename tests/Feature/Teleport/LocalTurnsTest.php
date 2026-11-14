<?php

use App\Enums\TeleportState;
use App\Enums\ThreadVisibility;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use App\Services\SessionStore\SessionStore;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config([
        'yak.threads.session_store_path' => storage_path('framework/testing/session-store-' . uniqid()),
        'yak.threads.session_store_key' => base64_encode(sodium_crypto_secretbox_keygen()),
    ]);
});

afterEach(fn () => File::deleteDirectory(config('yak.threads.session_store_path')));

const LOCAL_SESSION = '11111111-2222-3333-4444-555555555555';

/**
 * @param  list<array<string, mixed>>  $lines
 */
function jsonl(array $lines): string
{
    return implode("\n", array_map(fn (array $line): string => json_encode($line), $lines)) . "\n";
}

function localTranscript(int $extraTurns = 0): string
{
    $lines = [
        ['type' => 'summary', 'summary' => 'ignored'],
        ['type' => 'user', 'isMeta' => true, 'timestamp' => '2026-11-13T16:00:00Z', 'message' => ['role' => 'user', 'content' => 'meta caveat']],
        ['type' => 'user', 'timestamp' => '2026-11-13T16:12:00Z', 'message' => ['role' => 'user', 'content' => 'Find the **duplicate** plan']],
        ['type' => 'assistant', 'message' => ['model' => 'claude-sonnet-5-5', 'content' => [
            ['type' => 'thinking', 'thinking' => 'hmm'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'grep Unlimited -r app']],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'Read', 'input' => ['file_path' => 'app/Plan.php']],
            ['type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'Task', 'input' => ['description' => 'Explore pricing', 'subagent_type' => 'Explore']],
        ]]],
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'found with key sk-ant-abcdefgh12345678'],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'is_error' => true, 'content' => [['type' => 'text', 'text' => 'No such file']]],
        ]]],
        ['type' => 'assistant', 'isSidechain' => true, 'message' => ['content' => [['type' => 'text', 'text' => 'subagent chatter']]]],
        ['type' => 'assistant', 'message' => ['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'It is `PlanCatalog`.']]]],
        ['type' => 'user', 'timestamp' => '2026-11-14T09:00:00Z', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Fix it']]]],
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Done.']]]],
    ];

    for ($i = 0; $i < $extraTurns; $i++) {
        $lines[] = ['type' => 'user', 'timestamp' => '2026-11-14T10:00:00Z', 'message' => ['role' => 'user', 'content' => "Extra {$i}"]];
        $lines[] = ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => "Reply {$i}"]]]];
    }

    return jsonl($lines);
}

/**
 * A thread whose owner teleported this session up, with the transcript stored and a Yak turn appended after it.
 *
 * @return array{0: Thread, 1: User}
 */
function teleportedThread(int $extraTurns = 0): array
{
    $user = User::factory()->create(['name' => 'Mathias Hansen']);
    $thread = Thread::factory()->create(['owner_user_id' => $user->id]);
    $store = app(SessionStore::class);
    $body = localTranscript($extraTurns);
    $path = LOCAL_SESSION . '.jsonl';
    $store->append($thread, LOCAL_SESSION, $path, 0, $body, hash('sha256', $body), hash('sha256', ''));
    $yakTurn = jsonl([['type' => 'user', 'timestamp' => '2026-11-14T11:00:00Z', 'message' => ['role' => 'user', 'content' => 'Yak turn']]]);
    Teleport::factory()->up()->inState(TeleportState::Completed)->create([
        'thread_id' => $thread->id,
        'user_id' => $user->id,
        'session_id' => LOCAL_SESSION,
        'completed_at' => now(),
        'payload' => ['uploaded_files' => [['rel_path' => $path, 'length' => strlen($body), 'sha256' => null]]],
    ]);
    $store->append($thread, LOCAL_SESSION, $path, strlen($body), $yakTurn, hash('sha256', $yakTurn), hash('sha256', substr($body, -256)));

    return [$thread, $user];
}

it('groups the uploaded transcript into turns and stops at the teleport', function () {
    [$thread, $user] = teleportedThread();

    $this->actingAs($user)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('localTurns.count', 2)
        ->where('localTurns.firstPromptText', 'Find the **duplicate** plan')
        ->where('localTurns.startedAt', '2026-11-13T16:12:00Z')
        ->where('localTurns.endedAt', '2026-11-14T09:00:00Z')
        ->where('localTurns.hasMore', false)
        ->where('localTurns.authorInitials', 'MH')
        ->has('localTurns.turns', 2)
        ->where('localTurns.turns.0.html', "<p>Find the <strong>duplicate</strong> plan</p>\n")
        ->has('localTurns.turns.0.items', 2)
        ->where('localTurns.turns.0.items.0.kind', 'tools')
        ->has('localTurns.turns.0.items.0.steps', 3)
        ->where('localTurns.turns.0.items.0.steps.0.category', 'command')
        ->where('localTurns.turns.0.items.0.steps.0.content.output', 'found with key [redacted]')
        ->where('localTurns.turns.0.items.0.steps.1.failed', true)
        ->where('localTurns.turns.0.items.0.steps.2.label', 'Agent: Explore pricing')
        ->where('localTurns.turns.0.items.1.kind', 'text')
        ->where('localTurns.turns.0.items.1.html', "<p>It is <code>PlanCatalog</code>.</p>\n")
        ->where('localTurns.turns.1.text', 'Fix it'));
});

it('opens on the latest ten turns and pages earlier ones', function () {
    [$thread, $user] = teleportedThread(40);

    $this->actingAs($user)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('localTurns.count', 42)
        ->where('localTurns.hasMore', true)
        ->has('localTurns.turns', 10)
        ->where('localTurns.turns.0.id', 33));

    $this->actingAs($user)->getJson(route('threads.local-turns', ['thread' => $thread, 'before' => 33, 'limit' => 25]))
        ->assertOk()
        ->assertJsonCount(25, 'turns')
        ->assertJsonPath('turns.0.id', 8)
        ->assertJsonPath('hasMore', true);

    $this->actingAs($user)->getJson(route('threads.local-turns', ['thread' => $thread, 'before' => 8, 'limit' => 25]))
        ->assertJsonCount(7, 'turns')
        ->assertJsonPath('hasMore', false);
});

it('shows nothing without a completed teleport up', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page->where('localTurns', null));
});

it('gives nothing to a person who cannot read the thread', function () {
    [$thread] = teleportedThread();
    $thread->update(['visibility' => ThreadVisibility::Private]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->getJson(route('threads.local-turns', ['thread' => $thread, 'before' => 3]))->assertNotFound();
    $this->actingAs($stranger)->get(route('threads.show', $thread))->assertNotFound();
});
