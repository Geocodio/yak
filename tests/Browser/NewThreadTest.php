<?php

use App\Enums\ThreadBlockReason;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserRepositorySetting;

it('starts a thread with the account and model pickers', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'name' => 'Main', 'validated_at' => now()]);
    $other = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'Spare', 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertAttribute('[data-testid="start-thread-description"]', 'placeholder', 'What should Yak do? Say what is wrong or what you want, and where to look if you know.')
        ->assertDontSee('What should Yak work on?')
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->click('[aria-label="Claude account"]')
        ->click('Spare')
        ->click('[data-testid="composer-model"]')
        ->click('[data-testid="composer-model-haiku"]')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/');

    $thread = Thread::query()->latest('id')->firstOrFail();
    expect($thread->claude_account_id)->toBe($other->id)->and($thread->model)->toBe('haiku');
});

it('shows the approved start controls', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Start change')
        ->assertDontSee('Start thread')
        ->click('[data-testid="start-mode-research"]')
        ->assertSee('Start research')
        ->assertSeeIn('[data-testid="start-thread-form"]', '⌘');
});

it('shows Pick a repo and a disabled button when no repo is chosen', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => false]);
    Repository::factory()->create(['slug' => 'acme/gadgets', 'is_default' => false]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Pick a repo')
        ->assertAttribute('[data-testid="start-thread-description"]', 'placeholder', 'What should Yak do? Say what is wrong or what you want, and where to look if you know.')
        ->assertDisabled('[data-testid="start-thread-submit"]');
});

it('still submits with Cmd/Ctrl+Enter', function (string $chord) {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->keys('[data-testid="start-thread-description"]', $chord)
        ->assertPathBeginsWith('/threads/');
})->with(['Meta+Enter', 'Control+Enter']);

it('shows How accounts work and the mode hint', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('How accounts work')
        ->assertSee('Yak makes the change and opens a pull request for you to review.')
        ->assertSee('Type @ to pick a repo.')
        ->click('[data-testid="start-mode-research"]')
        ->assertSee('No pull request.');
});

it('falls back to the shared account when the user has none of their own', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->system()->create(['name' => 'Yak Geocodio', 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('You have no Claude account yet, so this task runs on the shared account Yak Geocodio.')
        ->assertSee('Add your own')
        ->assertMissing('[data-testid="new-thread-no-account"]')
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->assertEnabled('[data-testid="start-thread-submit"]')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/');
});

it('shows the connect-an-account state instead of the form when every account is unusable', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->system()->expired()->create(['validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertVisible('[data-testid="new-thread-no-account"]')
        ->assertSee('Connect a Claude account')
        ->assertMissing('[data-testid="start-thread-description"]')
        ->click('[data-testid="new-thread-add-account"]')
        ->assertSee('claude setup-token');
});

it('shows the add-a-repository state instead of the form when no repository exists', function () {
    $this->actingAs(User::factory()->create());

    visit(route('threads.new'))
        ->assertVisible('[data-testid="new-thread-no-repository"]')
        ->assertSee('Add a repository first')
        ->assertMissing('[data-testid="start-thread-description"]')
        ->click('[data-testid="new-thread-add-repository"]')
        ->assertPathIs('/repos/create');
});

it('shows a setup strip and queues the change while the repository is being set up', function () {
    $user = User::factory()->create();
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertVisible('[data-testid="new-thread-repo-setting-up"]')
        ->assertSee('Setting up acme/widgets')
        ->assertSee('Your thread starts as soon as it is ready.')
        ->assertSee('Queue change')
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->click('[data-testid="start-thread-submit"]')
        ->assertPathBeginsWith('/threads/');

    expect(Thread::query()->latest('id')->firstOrFail()->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
});

it('does not offer to queue when the repository setup failed', function () {
    $user = User::factory()->create();
    Repository::factory()->pendingSetup()->create(['slug' => 'acme/widgets', 'is_default' => true, 'setup_status' => 'failed']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertVisible('[data-testid="new-thread-repo-setup-failed"]')
        ->assertSee('Setup failed for acme/widgets')
        ->assertSee('Open repository')
        ->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->assertDisabled('[data-testid="start-thread-submit"]');
});

it('leaves the normal form untouched when the repository is ready', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertMissing('[data-testid="new-thread-repo-setting-up"]')
        ->assertMissing('[data-testid="new-thread-no-repository"]')
        ->assertMissing('[data-testid="new-thread-no-account"]')
        ->assertSee('Start change');
});

it('names the broken repo default and the account the task runs on', function () {
    $user = User::factory()->create();
    $repository = Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    $expired = ClaudeAccount::factory()->expired()->create(['owner_user_id' => $user->id, 'name' => 'Old', 'validated_at' => now()]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'name' => 'Fresh', 'validated_at' => now()]);
    UserRepositorySetting::query()->create(['user_id' => $user->id, 'repo' => $repository->slug, 'claude_account_id' => $expired->id]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Your default for this repo, Old, needs a new secret, so this task runs on your default account, Fresh.')
        ->assertSee('Replace the secret');
});

it('shows the start-failed banner when the start is rejected', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('threads.new'))->assertSee('Start change');
    Repository::query()->update(['setup_status' => 'failed']);

    $page->type('[data-testid="start-thread-description"]', 'Fix the flaky checkout test')
        ->click('[data-testid="start-thread-submit"]')
        ->assertSee('Yak could not start the task.')
        ->assertSee('Setup failed for acme/widgets')
        ->assertVisible('[data-testid="start-thread-error"]');
});

it('shows the add-a-repository state in the dialog and closes it with Not now', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->click('[data-testid="new-thread"]')
        ->assertVisible('[data-testid="new-thread-no-repository"]')
        ->click('[data-testid="new-thread-not-now"]')
        ->assertMissing('[data-testid="new-thread-dialog"]');
});

it('opens the account dialog in place from the dialog state', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'is_default' => true]);
    $this->actingAs($user);

    visit('/')
        ->click('[data-testid="new-thread"]')
        ->assertVisible('[data-testid="new-thread-no-account"]')
        ->click('[data-testid="new-thread-add-account"]')
        ->assertSee('claude setup-token')
        ->assertPathIs('/');
});
