<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\SlashCommands;

function slashMenuThread(): array
{
    $user = User::factory()->create();
    $repository = Repository::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'repo' => $repository->slug]);
    YakTask::factory()->create(['thread_id' => $thread->id]);
    app(SlashCommands::class)->capture($thread, [
        'commands' => [
            ['name' => 'grilling', 'description' => 'Grill you about a plan', 'argumentHint' => '<topic>', 'builtin' => false],
            ['name' => 'regrill', 'description' => 'Grill again', 'argumentHint' => null, 'builtin' => false],
            ['name' => 'green-ci', 'description' => 'Fix CI until it passes', 'argumentHint' => null, 'builtin' => false],
        ],
        'skills' => ['grilling', 'regrill'],
        'terminal' => [],
    ]);

    return [$user, $thread];
}

const SLASH_INPUT = '[data-testid="thread-composer-input"]';

it('opens on a leading slash, filters, moves with the arrows and completes with Tab', function () {
    [$user, $thread] = slashMenuThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}");
    $page->fill(SLASH_INPUT, '/')
        ->assertPresent('[data-testid="thread-slash-menu"]')
        ->assertSee('Skills')
        ->assertSee('Commands')
        ->assertSee('Yak')
        ->assertSee('<topic>')
        ->fill(SLASH_INPUT, '/gri')
        ->assertPresent('[data-name="grilling"]')
        ->assertPresent('[data-name="regrill"]')
        ->assertMissing('[data-name="green-ci"]')
        ->assertAttribute('[data-name="grilling"]', 'aria-selected', 'true')
        ->keys(SLASH_INPUT, 'ArrowDown')
        ->assertAttribute('[data-name="regrill"]', 'aria-selected', 'true')
        ->keys(SLASH_INPUT, 'Tab')
        ->assertValue(SLASH_INPUT, '/regrill ')
        ->assertMissing('[data-testid="thread-slash-menu"]');
    $page->assertNoJavaScriptErrors();
});

it('closes on Escape, ignores a slash that is not at the start and explains an unknown name', function () {
    [$user, $thread] = slashMenuThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}");
    $page->fill(SLASH_INPUT, '/gr')
        ->assertPresent('[data-testid="thread-slash-menu"]')
        ->keys(SLASH_INPUT, 'Escape')
        ->assertMissing('[data-testid="thread-slash-menu"]')
        ->fill(SLASH_INPUT, 'please /gr')
        ->assertMissing('[data-testid="thread-slash-menu"]')
        ->fill(SLASH_INPUT, '/xyz')
        ->assertSeeIn('[data-testid="thread-slash-menu-empty"]', 'No command named /xyz. It will be sent to Claude as text.');
    $page->assertNoJavaScriptErrors();
});

it('completes the name when a row is clicked', function () {
    [$user, $thread] = slashMenuThread();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}");
    $page->fill(SLASH_INPUT, '/')
        ->click('[data-name="green-ci"]')
        ->assertValue(SLASH_INPUT, '/green-ci ')
        ->assertMissing('[data-testid="thread-slash-menu"]');
    $page->assertNoJavaScriptErrors();
});
