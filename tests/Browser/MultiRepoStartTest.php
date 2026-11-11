<?php

use App\Enums\RepositoryRole;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Configures a GitHub App installation that can read every repository.
 */
function allowGitHubAccess(): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);
}

it('adds a context repo, shows the note and count, and shows progress after starting', function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);
    Queue::fake();

    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'is_default' => true]);
    Repository::factory()->create(['slug' => 'acme/docs']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Move invoices to cursor pagination')
        ->click('[data-testid="add-repo"]')
        ->click('acme/docs')
        ->assertCount('[data-testid="repo-chip"]', 2)
        ->assertSeeIn('[data-testid="expected-pr-count"]', '2 pull requests expected')
        ->click('[data-testid="repo-role"]')
        ->click('Context')
        ->assertSeeIn('[data-testid="expected-pr-count"]', '1 pull request expected')
        ->assertSeeIn('[data-testid="repo-note"]', 'acme/docs is throwaway')
        ->click('[data-testid="start-thread-submit"]')
        ->assertVisible('[data-testid="repo-progress"]')
        ->assertSee('acme/docs')
        ->assertDontSee('Queued')
        ->assertDontSee('Running setup')
        ->assertCount('[data-testid="repo-progress-row"]', 2);

    $thread = Thread::query()->latest('id')->firstOrFail();
    expect($thread->repositories()->where('repo', 'acme/docs')->value('role'))->toBe(RepositoryRole::Context);
});

it('shows avatar chips, a Repositories label and a primary menu that switches the primary repo', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'is_default' => true]);
    Repository::factory()->create(['slug' => 'acme/docs']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSee('Repositories')
        ->assertSeeIn('[data-testid="repo-avatar"]', 'AP')
        ->click('[data-testid="start-thread-repo"]')
        ->click('acme/docs')
        ->assertSeeIn('[data-testid="repo-chip"]', 'acme/docs')
        ->assertSeeIn('[data-testid="repo-avatar"]', 'DO')
        ->assertVisible('button[aria-label="Add repo"]');
});

it('labels the submit button by mode and shows the footer sentence, counter and note link', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'is_default' => true]);
    Repository::factory()->create(['slug' => 'acme/docs']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    config()->set('yak.channels.github.app_id', '999');
    Http::fake(['*' => Http::response(['description' => null, 'topics' => []])]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->assertSeeIn('[data-testid="start-thread-submit"]', 'Start change')
        ->assertSeeIn('[data-testid="start-thread-footer"]', 'opens a pull request for you to review')
        ->click('[data-testid="add-repo"]')
        ->click('acme/docs')
        ->assertSeeIn('[data-testid="expected-pr-count"]', '2 pull requests expected')
        ->assertSeeIn('[data-testid="repo-note"]', 'How multi-repo threads work')
        ->click('[data-testid="start-mode-research"]')
        ->assertSeeIn('[data-testid="start-thread-submit"]', 'Start research')
        ->assertSeeIn('[data-testid="start-thread-footer"]', 'No pull request.')
        ->assertMissing('[data-testid="expected-pr-count"]');
});

it('disables Add repo and shows the counter at six repositories', function () {
    $user = User::factory()->create();
    foreach (['api', 'docs', 'web', 'cli', 'sdk', 'ops', 'spare'] as $name) {
        Repository::factory()->create(['slug' => "acme/{$name}", 'is_default' => $name === 'api']);
    }
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    allowGitHubAccess();
    $this->actingAs($user);

    $page = visit(route('threads.new'));
    foreach (['docs', 'web', 'cli', 'sdk', 'ops'] as $name) {
        $page->click('[data-testid="add-repo"]')->click("acme/{$name}");
    }

    $page->assertCount('[data-testid="repo-chip"]', 6)
        ->assertSeeIn('[data-testid="repo-limit"]', '6 of 6 repos')
        ->assertSeeIn('[data-testid="repo-limit"]', 'A thread can use up to 6 repos.')
        ->assertAttribute('button[aria-label="Add repo"]', 'disabled', '');
});

it('shows an unreachable chip and callout, blocks submit, and Remove it clears the block', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'is_default' => true]);
    Repository::factory()->create(['slug' => 'acme/secret']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Move invoices to cursor pagination')
        ->click('[data-testid="add-repo"]')
        ->click('acme/secret')
        ->assertAttribute('[data-unreachable="true"]', 'data-unreachable', 'true')
        ->assertSeeIn('[data-testid="repo-error"]', 'Yak cannot reach acme/secret.')
        ->assertSeeIn('[data-testid="repo-error"]', 'How Yak gets access')
        ->assertSeeIn('[data-testid="start-thread-blocked"]', 'Remove acme/secret or fix its access to start.')
        ->assertAttribute('[data-testid="start-thread-submit"]', 'disabled', '')
        ->click('[data-testid="repo-remove-blocked"]')
        ->assertMissing('[data-testid="start-thread-blocked"]')
        ->assertCount('[data-testid="repo-chip"]', 1);
});
