<?php

use App\DataTransferObjects\AccountValidation;
use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use App\Services\Threads\ThreadCommands;

beforeEach(function () {
    $validator = Mockery::mock(ClaudeAccountValidator::class);
    $validator->shouldReceive('validate')->andReturn(new AccountValidation(true, null));
    app()->instance(ClaudeAccountValidator::class, $validator);
    app()->instance(ThreadCommands::class, Mockery::mock(ThreadCommands::class)->shouldIgnoreMissing());
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a person adds an account and replaces a secret', function () {
    $page = visit('/settings/claude-accounts');

    $page->assertNoJavaScriptErrors()
        ->assertPresent('[data-testid="no-accounts"]')
        ->click('Use an API key')
        ->type('[data-testid="account-secret"]', 'sk-ant-api03-' . str_repeat('a', 30))
        ->fill('[data-testid="account-name"]', 'Work')
        ->click('[data-testid="account-save"]')
        ->assertPresent('[data-testid="account-connected"]')
        ->click('[data-testid="account-connected-done"]')
        ->assertSee('Work');

    $account = ClaudeAccount::query()->firstOrFail();

    $page->click('Replace secret')
        ->type('[data-testid="account-secret"]', 'sk-ant-api03-' . str_repeat('b', 30))
        ->click('[data-testid="account-save"]')
        ->assertPresent('[data-testid="account-' . $account->id . '"]')
        ->click('[aria-label="More actions for Work"]')
        ->click('Edit name and appearance')
        ->fill('[data-testid="account-max-concurrent-turns"]', '7')
        ->click('[data-testid="account-edit-save"]')
        ->assertDontSee('Edit Work');

    expect($account->refresh()->max_concurrent_turns)->toBe(7);

    $page->click('[aria-label="More actions for Work"]')
        ->click('Remove account')
        ->click('[role="alertdialog"] button:has-text("Remove account")')
        ->assertPresent('[data-testid="no-accounts"]');

    expect(ClaudeAccount::query()->count())->toBe(0);
});

test('a pasted token with a line break connects and shows the connected state', function () {
    $page = visit('/settings/claude-accounts');

    $page->assertNoJavaScriptErrors()
        ->click('Use your Claude subscription')
        ->fill('[data-testid="account-secret"]', 'sk-ant-oat01-' . str_repeat('a', 20) . "\n" . str_repeat('b', 20))
        ->assertSee('Setup token. It works for one year')
        ->click('[data-testid="account-save"]')
        ->assertPresent('[data-testid="account-connected"]')
        ->assertSee('Claude account connected');

    expect(ClaudeAccount::query()->firstOrFail()->name)->toBe('Personal');
});

test('a non-admin sees no Yak account buttons', function () {
    ClaudeAccount::factory()->system()->create(['validated_at' => now()]);

    visit('/settings/claude-accounts')
        ->assertSee('Admins only')
        ->assertNotPresent('[data-testid="add-yak-account"]');
});

test('the empty state names the shared account', function () {
    ClaudeAccount::factory()->system()->create(['name' => 'Yak Geocodio', 'validated_at' => now()]);

    visit('/settings/claude-accounts')
        ->assertSee('your tasks run on the shared Yak account, Yak Geocodio.');
});

test('an admin with no Yak account sees one Add Yak account button', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    visit('/settings/claude-accounts')
        ->assertSee('No Yak account yet')
        ->assertScript('document.querySelectorAll(\'[data-testid="add-yak-account"]\').length', 1);
});

test('an admin with a Yak account sees the add button in the section header', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    ClaudeAccount::factory()->system()->create(['validated_at' => now()]);

    visit('/settings/claude-accounts')
        ->assertScript('document.querySelectorAll(\'[data-testid="add-yak-account"]\').length', 1);
});
