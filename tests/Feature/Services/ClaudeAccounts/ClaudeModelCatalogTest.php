<?php

use App\Console\Commands\RefreshClaudeModelsCommand;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadCommands;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function modelsResponse(array $data, bool $hasMore = false, ?string $lastId = null): array
{
    return ['data' => $data, 'has_more' => $hasMore, 'first_id' => $data[0]['id'] ?? null, 'last_id' => $lastId ?? ($data[array_key_last($data)]['id'] ?? null)];
}

function modelItem(string $id, string $displayName, string $createdAt): array
{
    return ['type' => 'model', 'id' => $id, 'display_name' => $displayName, 'created_at' => $createdAt];
}

beforeEach(function () {
    config(['yak.threads.auth_proxy_url' => 'http://proxy.test:8787']);
    ClaudeAccount::factory()->create(['owner_user_id' => null, 'is_default' => true]);
});

it('parses a paginated response, drops unknown families and strips the Claude prefix', function () {
    Http::fake([
        'proxy.test:8787/v1/models?limit=1000' => Http::response(modelsResponse([
            modelItem('claude-opus-5-5', 'Claude Opus 5.5', '2026-05-01T00:00:00Z'),
            modelItem('gpt-5', 'GPT 5', '2026-05-01T00:00:00Z'),
        ], hasMore: true, lastId: 'claude-opus-5-5')),
        'proxy.test:8787/v1/models?limit=1000&after_id=claude-opus-5-5' => Http::response(modelsResponse([
            modelItem('claude-opus-5', 'Claude Opus 5', '2026-02-01T00:00:00Z'),
            modelItem('claude-sonnet-5-5', 'Claude Sonnet 5.5', '2026-05-01T00:00:00Z'),
            modelItem('claude-mystery-1', 'Claude Mystery 1', '2026-05-01T00:00:00Z'),
        ])),
    ]);

    $catalog = app(ClaudeModelCatalog::class);

    expect($catalog->refresh())->toBeTrue()
        ->and(array_column($catalog->entries(), 'id'))->toBe(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-opus-5'])
        ->and($catalog->entries()[0])->toMatchArray(['label' => 'Opus 5.5', 'family' => 'opus', 'isLatestInFamily' => true, 'description' => 'For complex work and everyday tasks'])
        ->and($catalog->entries()[2]['isLatestInFamily'])->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->hasHeader('anthropic-beta', 'oauth-2025-04-20') && str_starts_with($request->header('Authorization')[0], 'Bearer yakph-'));
});

it('lists one row per family in order with older versions under it', function () {
    Cache::forever(ClaudeModelCatalog::CACHE_KEY, [
        ['id' => 'claude-haiku-5-5', 'family' => 'haiku', 'label' => 'Haiku 5.5', 'createdAt' => '2026-05-01T00:00:00Z'],
        ['id' => 'claude-opus-5-5', 'family' => 'opus', 'label' => 'Opus 5.5', 'createdAt' => '2026-05-01T00:00:00Z'],
        ['id' => 'claude-opus-5', 'family' => 'opus', 'label' => 'Opus 5', 'createdAt' => '2026-02-01T00:00:00Z'],
    ]);

    $families = app(ClaudeModelCatalog::class)->families();

    expect(array_column($families, 'family'))->toBe(['opus', 'haiku'])
        ->and($families[0]['latest'])->toBe(['value' => 'opus', 'label' => 'Opus 5.5', 'description' => 'For complex work and everyday tasks'])
        ->and($families[0]['older'])->toBe([['value' => 'claude-opus-5', 'label' => 'Opus 5']])
        ->and($families[1]['older'])->toBe([]);
});

it('falls back to the seed list before any fetch has succeeded', function () {
    $catalog = app(ClaudeModelCatalog::class);

    expect(array_column($catalog->entries(), 'id'))->toEqualCanonicalizing(['claude-fable-5-2', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'])
        ->and(array_column($catalog->families(), 'family'))->toBe(['opus', 'fable', 'sonnet', 'haiku']);
});

it('keeps the last good list when a fetch fails', function () {
    Http::fakeSequence()
        ->push(modelsResponse([modelItem('claude-opus-9', 'Claude Opus 9', '2027-01-01T00:00:00Z')]))
        ->push(['error' => 'nope'], 500);

    $catalog = app(ClaudeModelCatalog::class);

    expect($catalog->refresh())->toBeTrue()
        ->and($catalog->refresh())->toBeFalse()
        ->and(array_column($catalog->entries(), 'id'))->toBe(['claude-opus-9']);
});

it('resolves an alias to the newest id and passes anything else through', function () {
    Cache::forever(ClaudeModelCatalog::CACHE_KEY, [
        ['id' => 'claude-opus-5', 'family' => 'opus', 'label' => 'Opus 5', 'createdAt' => '2026-02-01T00:00:00Z'],
        ['id' => 'claude-opus-5-5', 'family' => 'opus', 'label' => 'Opus 5.5', 'createdAt' => '2026-05-01T00:00:00Z'],
    ]);

    $catalog = app(ClaudeModelCatalog::class);

    expect($catalog->resolve('opus'))->toBe('claude-opus-5-5')
        ->and($catalog->resolve('claude-opus-5'))->toBe('claude-opus-5')
        ->and($catalog->resolve('haiku'))->toBe('haiku');
});

it('validates aliases and exact ids and rejects junk', function (string $value, bool $passes) {
    Cache::forever(ClaudeModelCatalog::CACHE_KEY, [
        ['id' => 'claude-opus-5', 'family' => 'opus', 'label' => 'Opus 5', 'createdAt' => '2026-02-01T00:00:00Z'],
    ]);

    expect(validator(['model' => $value], ['model' => [ClaudeModelCatalog::rule()]])->passes())->toBe($passes);
})->with([
    'alias' => ['sonnet', true],
    'new family alias' => ['fable', true],
    'exact id' => ['claude-opus-5', true],
    'junk' => ['gpt-4', false],
]);

it('schedules the daily refresh', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'yak:refresh-claude-models'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and(class_exists(RefreshClaudeModelsCommand::class))->toBeTrue();
});

it('sends the resolved id to the runner', function () {
    $thread = Thread::factory()->create(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running', 'model' => 'sonnet']);
    app(SandboxManager::class)->queueExecResult(Process::result("10.100.0.7 \n"));
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->andReturn('yakph-test');

    (new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class)))
        ->startRunner($thread, ClaudeAccount::factory()->create(), null, 'Go.');

    expect(app(BrokerStreams::class)->commands('threads:' . $thread->id)[0]['start']['model'])->toBe('claude-sonnet-5-5');
});
