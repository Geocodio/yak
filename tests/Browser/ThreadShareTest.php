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

    visit('/threads/' . $private->public_id)
        ->assertVisible('[data-testid="visibility-badge"]')
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Private')
        ->assertVisible('[data-testid="private-banner"]')
        ->assertSee('Only you can read this thread')
        ->assertVisible('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();

    visit('/threads/' . $team->public_id)
        ->assertMissing('[data-testid="visibility-badge"]')
        ->assertMissing('[data-testid="private-banner"]')
        ->assertMissing('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();
});

test('a private thread shows the started-on-your-computer subtext and the owner role', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    visit('/threads/' . $thread->public_id)
        ->click('[data-testid="share-button"]')
        ->assertSee('A thread started on your computer is private to you until you share it.')
        ->click('[data-testid="share-mode-people"]')
        ->assertSeeIn('[data-testid="share-role-' . $owner->id . '"]', 'Owner')
        ->assertNoJavaScriptErrors();
});

test('the dialog lists people and the secret warning from the transcript', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create(['name' => 'Tessa Teammate']);
    $thread = Thread::factory()->private($owner)->create();
    openedSecretFile($thread, '/repo/.env');
    $this->actingAs($owner);

    visit('/threads/' . $thread->public_id)
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

    visit('/threads/' . $thread->public_id)
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
    visit('/threads/' . $thread->public_id)
        ->assertSee($thread->title)
        ->assertMissing('[data-testid="share-button"]')
        ->assertNoJavaScriptErrors();
});

test('Everyone on the team flips the badge to Shared', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    visit('/threads/' . $thread->public_id)
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

    visit('/threads/' . $thread->public_id)
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-everyone"]')
        ->click('[data-testid="share-submit"]')
        ->assertVisible('[data-testid="share-error"]')
        ->assertSee('This thread is still private')
        ->assertVisible('[data-testid="share-error"][data-tone="fail"]')
        ->assertVisible('[data-testid="share-dialog"]');

    expect($thread->fresh()->visibility->value)->toBe('private');
});

test('phone width 390: dialog fits and scrolls', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    User::factory()->count(12)->create();
    openedSecretFile($thread, '/repo/.env');
    $this->actingAs($owner);

    $page = visit('/threads/' . $thread->public_id)->on()->mobile()
        ->click('[data-testid="thread-menu"]')
        ->click('[data-testid="thread-menu-share"]')
        ->click('[data-testid="share-mode-people"]')
        ->assertVisible('[data-testid="share-submit"]')
        ->assertNoJavaScriptErrors();

    $fits = $page->script(<<<'JS'
        (() => { const r = document.querySelector('[data-testid="share-dialog"]').getBoundingClientRect();
            return r.left >= 0 && r.right <= window.innerWidth && r.bottom <= window.innerHeight; })()
    JS);

    expect($fits)->toBeTrue();
});

test('the visibility row shows the shared people and Remove narrows them down to Private', function () {
    $owner = User::factory()->create();
    [$first, $second] = User::factory()->count(2)->create()->all();
    $thread = Thread::factory()->private($owner)->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$first->id, $second->id]);
    $this->actingAs($owner);

    visit('/threads/' . $thread->public_id)
        ->assertSeeIn('[data-testid="visibility-badge"]', 'Shared with 2 people')
        ->assertSeeIn('[data-testid="visibility-row-state"]', 'Shared with 2 people')
        ->click('[data-testid="share-remove-' . $first->id . '"]')
        ->assertSeeIn('[data-testid="visibility-row-state"]', 'Shared with 1 person')
        ->assertMissing('[data-testid="share-remove-' . $first->id . '"]')
        ->click('[data-testid="share-remove-' . $second->id . '"]')
        ->assertSeeIn('[data-testid="visibility-row-state"]', 'Private')
        ->assertMissing('[data-testid="shared-people"]')
        ->assertNoJavaScriptErrors();

    expect($thread->fresh()->visibility->value)->toBe('private')
        ->and(app(ThreadSharing::class)->current($thread)['userIds'])->toBe([]);
});

test('a private thread shared with nobody shows Private and a Share link with no empty list', function () {
    $owner = User::factory()->create();
    User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $this->actingAs($owner);

    visit('/threads/' . $thread->public_id)
        ->assertSeeIn('[data-testid="visibility-row-state"]', 'Private')
        ->assertSeeIn('[data-testid="visibility-row-share"]', 'Share')
        ->assertMissing('[data-testid="shared-people"]')
        ->assertNoJavaScriptErrors();
});
