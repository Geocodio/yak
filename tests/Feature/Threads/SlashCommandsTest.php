<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\SlashCommands;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadMessageSender;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

function commandsLine(): array
{
    return [
        'type' => 'runner',
        'event' => 'commands',
        'commands' => [
            ['name' => 'greet', 'description' => 'Say hello', 'argumentHint' => '<who>', 'builtin' => false],
            ['name' => 'review-notes', 'description' => 'Review notes', 'argumentHint' => null, 'builtin' => false],
            ['name' => 'compact', 'description' => 'Compact the context', 'argumentHint' => '', 'builtin' => true],
            ['name' => 'config', 'description' => 'Open settings', 'argumentHint' => null, 'builtin' => true],
            ['name' => 'doctor', 'description' => 'Diagnose', 'argumentHint' => null, 'builtin' => true],
            ['name' => 'clear', 'description' => 'Claude clear', 'argumentHint' => null, 'builtin' => true],
        ],
        'skills' => ['greet'],
        'terminal' => ['doctor'],
    ];
}

it('stores the filtered list on the thread and its repository', function () {
    $repository = Repository::factory()->create();
    $thread = Thread::factory()->create(['repo' => $repository->slug]);

    app(SlashCommands::class)->capture($thread, commandsLine());

    $expected = [
        ['name' => 'greet', 'description' => 'Say hello', 'argumentHint' => '<who>', 'kind' => 'skill'],
        ['name' => 'review-notes', 'description' => 'Review notes', 'argumentHint' => null, 'kind' => 'command'],
        ['name' => 'compact', 'description' => 'Compact the context', 'argumentHint' => null, 'kind' => 'command'],
        ['name' => 'clear', 'description' => 'Claude clear', 'argumentHint' => null, 'kind' => 'command'],
    ];

    expect($thread->fresh()->slash_commands)->toBe($expected)
        ->and($thread->fresh()->slash_commands_captured_at)->not->toBeNull()
        ->and($repository->fresh()->slash_commands)->toBe($expected)
        ->and($repository->fresh()->slash_commands_captured_at)->not->toBeNull();
});

it('captures the list from a runner line through the thread driver', function () {
    [$thread] = runningTurnFor();

    app(BrokerStreams::class)->register('threads:' . $thread->id);
    app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, commandsLine());
    Artisan::call('yak:thread-drivers', ['--once' => true]);

    expect(array_column($thread->fresh()->slash_commands, 'name'))->toBe(['greet', 'review-notes', 'compact', 'clear']);
});

it('offers Yak commands first and lets them win a name collision', function () {
    $thread = Thread::factory()->create(['repo' => Repository::factory()->create()->slug]);
    app(SlashCommands::class)->capture($thread, commandsLine());

    $commands = app(SlashCommands::class)->forThread($thread->fresh());
    $clear = array_values(array_filter($commands['commands'], fn (array $command): bool => $command['name'] === 'clear'));

    expect($commands['commands'][0]['kind'])->toBe('yak')
        ->and($clear)->toHaveCount(1)
        ->and($clear[0]['kind'])->toBe('yak')
        ->and($commands['capturedAt'])->not->toBeNull();
});

it('falls back to the repository list for a thread that has not started a session', function () {
    $repository = Repository::factory()->create();
    $earlier = Thread::factory()->create(['repo' => $repository->slug]);
    app(SlashCommands::class)->capture($earlier, commandsLine());

    $fresh = Thread::factory()->create(['repo' => $repository->slug]);
    $names = array_column(app(SlashCommands::class)->forThread($fresh)['commands'], 'name');

    expect($fresh->slash_commands)->toBeNull()
        ->and($names)->toBe(['clear', 'greet', 'review-notes', 'compact']);
});

it('offers only the Yak commands when nothing was captured', function () {
    $thread = Thread::factory()->create(['repo' => Repository::factory()->create()->slug]);

    expect(array_column(app(SlashCommands::class)->forThread($thread)['commands'], 'name'))->toBe(['clear']);
});

it('queues a slash command sent during a running turn instead of steering it', function () {
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();
    app(SlashCommands::class)->capture($thread, commandsLine());
    $this->mock(SteeringDelivery::class)->shouldNotReceive('deliver');
    $user = User::factory()->create();

    $result = app(ThreadMessageSender::class)->send($thread->fresh(), $user, new ThreadMessageInput('/greet hello world', null, null, null, MessageDelivery::Steer, null));

    expect($result->status)->toBe('queued')
        ->and(PendingSteeringMessage::where('text', '/greet hello world')->sole()->mode->value)->toBe('queue');
});

it('exposes the list on the thread page props', function () {
    $this->withoutVite();
    $thread = Thread::factory()->paused()->create(['repo' => Repository::factory()->create()->slug]);
    app(SlashCommands::class)->capture($thread, commandsLine());

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slashCommands.commands.0.name', 'clear')
            ->where('slashCommands.commands.1.name', 'greet')
            ->has('slashCommands.capturedAt'));
});

it('matches only a known command at the start of the text', function (string $text, bool $expected) {
    $thread = Thread::factory()->create(['repo' => Repository::factory()->create()->slug]);
    app(SlashCommands::class)->capture($thread, commandsLine());

    expect(app(SlashCommands::class)->startsWithKnownCommand($thread->fresh(), $text))->toBe($expected);
})->with([
    ['/greet', true],
    ['/greet hello', true],
    ['/unknown thing', false],
    ['please /greet', false],
    ['/gree', false],
]);
