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
        ->click('API key')
        ->type('[data-testid="account-secret"]', 'sk-ant-api03-' . str_repeat('a', 30))
        ->type('input[placeholder="Geocodio work"]', 'Work')
        ->click('[data-testid="account-save"]')
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

test('a non-admin sees no Yak account buttons', function () {
    ClaudeAccount::factory()->system()->create(['validated_at' => now()]);

    visit('/settings/claude-accounts')
        ->assertSee('Admins only')
        ->assertNotPresent('[data-testid="add-yak-account"]');
});
