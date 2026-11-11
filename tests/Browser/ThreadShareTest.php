<?php

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Models\User;
use App\Services\SessionStore\SessionStore;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

afterEach(function () {
    if (isset($this->storePath)) {
        File::deleteDirectory($this->storePath);
    }
});

/**
 * Writes a main transcript for the thread in which Claude opened a secret-looking file.
 */
function openedSecretFile(Thread $thread, string $path): void
{
    test()->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', test()->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));

    $sessionId = (string) Str::uuid();
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => $sessionId]);
    $chunk = json_encode(['message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => $path]]]]]) . "\n";
    app(SessionStore::class)->append($thread, $sessionId, $sessionId . '.jsonl', 0, $chunk, hash('sha256', $chunk), hash('sha256', ''));
}

test('owner sees the Private badge, the banner and the Share button; a team thread shows none', function () {
    $owner = User::factory()->create();
    $private = Thread::factory()->private($owner)->create();
    $team = Thread::factory()->create();
    $this->actingAs($owner);

    visit('/threads/' . $private->id)
        ->assertVisible('[data-testid="visibility-badge"]')
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Private')
        ->assertVisible('[data-testid="private-banner"]')
        ->assertSee('Only you can read this thread')
        ->assertVisible('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();

    visit('/threads/' . $team->id)
        ->assertMissing('[data-testid="visibility-badge"]')
        ->assertMissing('[data-testid="private-banner"]')
        ->assertMissing('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();
});

test('the dialog lists people and the secret warning from the transcript', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Tessa Teammate']);
    $thread = Thread::factory()->private($owner)->create();
    openedSecretFile($thread, '/repo/.env');
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="share-button"]')
        ->assertVisible('[data-testid="share-dialog"]')
        ->assertVisible('[data-testid="share-secret-warning"]')
        ->assertSeeIn('[data-testid="share-secret-warning"]', '/repo/.env')
        ->click('[data-testid="share-mode-people"]')
        ->assertVisible('[data-testid="share-person-' . $teammate->id . '"]')
        ->assertSee('Tessa Teammate')
        ->assertNoJavaScriptErrors();
});

test('choosing People I pick and two people shares and the teammate then sees the thread', function () {
    $owner = User::factory()->create();
    [$first, $second] = User::factory()->count(2)->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-people"]')
        ->click('[data-testid="share-person-' . $first->id . '"]')
        ->click('[data-testid="share-person-' . $second->id . '"]')
        ->click('[data-testid="share-submit"]')
        ->assertMissing('[data-testid="share-dialog"]')
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Shared')
        ->assertNoJavaScriptErrors();

    expect(app(ThreadSharing::class)->current($thread)['userIds'])->toEqualCanonicalizing([$first->id, $second->id]);

    $this->actingAs($first);
    visit('/threads/' . $thread->id)
        ->assertSee($thread->title)
        ->assertMissing('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();
});

test('Everyone on the team flips the badge to Shared', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    visit('/threads/' . $thread->id)
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-everyone"]')
        ->click('[data-testid="share-submit"]')
        ->assertMissing('[data-testid="share-dialog"]')
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Shared')
        ->assertMissing('[data-testid="private-banner"]')
        ->assertNoJavaScriptErrors();

    expect($thread->fresh()->visibility->value)->toBe('team');
});

test('a failed save shows the error and keeps Private', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);
    $this->app->bind(ThreadSharing::class, fn () => new class extends ThreadSharing
    {
        public function apply(Thread $thread, User $actor, ThreadShareMode $mode, array $userIds = []): void
        {
            throw new RuntimeException('unreachable');
        }
    });

    visit('/threads/' . $thread->id)
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-everyone"]')
        ->click('[data-testid="share-submit"]')
        ->assertVisible('[data-testid="share-error"]')
        ->assertSee('This thread is still private')
        ->assertVisible('[data-testid="share-dialog"]');

    expect($thread->fresh()->visibility->value)->toBe('private');
});

test('phone width 390: dialog fits and scrolls', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    User::factory()->count(12)->create();
    openedSecretFile($thread, '/repo/.env');
    $this->actingAs($owner);

    $page = visit('/threads/' . $thread->id)->on()->mobile()
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-people"]')
        ->assertVisible('[data-testid="share-submit"]')
        ->assertNoJavaScriptErrors();

    $fits = $page->script(<<<'JS'
        (() => { const r = document.querySelector('[data-testid="share-dialog"]').getBoundingClientRect();
            return r.left >= 0 && r.right <= window.innerWidth && r.bottom <= window.innerHeight; })()
    JS);

    expect($fits)->toBeTrue();
});
