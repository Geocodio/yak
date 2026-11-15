<?php

use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use App\Providers\E2eServiceProvider;
use App\Services\E2e\E2eBrokerWire;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\RedisBrokerStreams;
use App\Services\Threads\ThreadStarter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

beforeEach(function (): void {
    $redis = startLocalRedis();

    if ($redis === null) {
        $this->markTestSkipped('redis-server is not installed.');
    }

    $GLOBALS['startedRedisServers'][] = $redis;

    foreach (['threads', 'threads-commands', 'thread-window'] as $connection) {
        config(["database.redis.{$connection}.host" => '127.0.0.1', "database.redis.{$connection}.port" => $redis['port'], "database.redis.{$connection}.url" => null]);
    }

    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    config(['yak.e2e.enabled' => true, 'yak.channels.github.installation_id' => 1]);
    (new E2eServiceProvider(app()))->bindFakes();
    (new E2eServiceProvider(app()))->boot();
    app()->singleton(BrokerStreams::class, fn () => new RedisBrokerStreams('threads', 'threads-commands'));
});

afterEach(fn () => stopStartedLocalRedis());

/**
 * Runs yak:e2e-seed with stdout and stderr kept apart.
 *
 * @return array{exit: int, out: string, err: string}
 */
function runSeed(): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $output = new class($out, new StreamOutput($err)) extends StreamOutput implements ConsoleOutputInterface
    {
        public function __construct($stream, private OutputInterface $errorOutput)
        {
            parent::__construct($stream);
        }

        public function getErrorOutput(): OutputInterface
        {
            return $this->errorOutput;
        }

        public function setErrorOutput(OutputInterface $error): void
        {
            $this->errorOutput = $error;
        }

        public function section(): ConsoleSectionOutput
        {
            throw new LogicException('Sections are not used.');
        }
    };

    $exit = Artisan::call('yak:e2e-seed', ['--url' => 'http://127.0.0.1:18000'], $output);
    rewind($out);
    rewind($err);

    return ['exit' => $exit, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
}

/**
 * @return array<string, mixed>
 */
function seededWorld(): array
{
    $result = runSeed();
    expect($result['exit'])->toBe(0, $result['err']);

    return json_decode(trim($result['out']), true, flags: JSON_THROW_ON_ERROR);
}

it('refuses outside e2e mode', function (): void {
    config(['yak.e2e.enabled' => false]);

    $result = runSeed();

    expect($result['exit'])->toBe(1)
        ->and($result['out'])->toBe('')
        ->and(User::query()->count())->toBe(0)
        ->and(Device::query()->count())->toBe(0)
        ->and(Thread::withoutGlobalScopes()->count())->toBe(0);
});

it('names an incomplete world on stderr and keeps stdout empty', function (): void {
    app()->bind(ThreadStarter::class, fn () => throw new RuntimeException('the starter broke'));

    $result = runSeed();

    expect($result['exit'])->toBe(1)
        ->and($result['out'])->toBe('')
        ->and($result['err'])->toContain('yak:e2e-seed: the world is incomplete: the starter broke. Run php artisan migrate:fresh first.');
});

it('refuses a database that already has data', function (): void {
    User::factory()->create();

    $result = runSeed();

    expect($result['exit'])->toBe(1)
        ->and($result['out'])->toBe('')
        ->and($result['err'])->toContain('The database already has data. Run php artisan migrate:fresh first.')
        ->and(User::query()->count())->toBe(1)
        ->and(Thread::withoutGlobalScopes()->count())->toBe(0);
});

it('prints the world as one JSON line', function (): void {
    $result = runSeed();
    $world = json_decode(trim($result['out']), true, flags: JSON_THROW_ON_ERROR);

    expect($result['exit'])->toBe(0)
        ->and(substr_count(trim($result['out']), "\n"))->toBe(0)
        ->and($world['url'])->toBe('http://127.0.0.1:18000')
        ->and($world['person'])->toBe('Test Person')
        ->and($world['phones']['main']['name'])->toBe('Test phone')
        ->and($world['phones']['second']['name'])->toBe('Second test phone')
        ->and($result['err'])->not->toBe('')
        ->and($result['err'])->not->toContain($world['phones']['main']['token'])
        ->and($result['err'])->not->toContain($world['phones']['second']['token']);

    foreach (['finished', 'question', 'held', 'changes'] as $key) {
        expect($world['threads'][$key]['id'])->toBeString()->not->toBe('')
            ->and($world['threads'][$key]['title'])->toBeString()->not->toBe('');
    }
});

it('makes the world the journeys read', function (): void {
    $world = seededWorld();
    $headers = fn (string $phone): array => ['X-Yak-Protocol' => '1', 'Authorization' => 'Bearer ' . $world['phones'][$phone]['token'], 'Accept' => 'application/json'];
    $threads = $world['threads'];

    foreach (['main', 'second'] as $phone) {
        expect($this->getJson('/api/app/v1/me', $headers($phone))->assertOk()->json('user.name'))->toBe('Test Person');
    }

    $listed = collect($this->getJson('/api/app/v1/threads', $headers('main'))->assertOk()->json())->flatten(1)->pluck('title');
    expect($listed->all())->toContain('Retry failed uploads', 'Pick a rule for failed rows', 'Look at the importer', 'Add an empty file check');

    $question = $this->getJson("/api/app/v1/threads/{$threads['question']['id']}", $headers('main'))->assertOk();
    expect($question->json('question.questions.0.header'))->toBe('On failure')
        ->and($question->json('question.questions.0.options'))->toHaveCount(2);

    $diff = $this->getJson("/api/app/v1/threads/{$threads['changes']['id']}/diff", $headers('main'))->assertOk();
    expect($diff->json('diff.files.*.path'))->toContain('src/upload.php', 'src/retry.php');

    $events = $this->getJson("/api/app/v1/threads/{$threads['finished']['id']}/events", $headers('main'))->assertOk();
    $assistantTexts = collect($events->json('events'))
        ->filter(fn (array $event): bool => $event['type'] === 'entry' && ($event['data']['role'] ?? null) === 'assistant')
        ->flatMap(fn (array $event): array => array_column($event['data']['blocks'], 'text'));
    expect($assistantTexts->all())->toContain('scrubbed text 1');

    $held = $this->getJson("/api/app/v1/threads/{$threads['held']['id']}", $headers('main'))->assertOk();
    expect($held->json('composer.state'))->toBe('idle');
});

it('leaves each seeded channel stream where its thread is', function (): void {
    $world = seededWorld();
    $client = Redis::connection('threads')->client();

    foreach ($world['threads'] as $seeded) {
        $thread = Thread::withoutGlobalScopes()->where('public_id', $seeded['id'])->firstOrFail();
        $channel = 'threads:' . $thread->id;
        $last = array_values($client->xRevRange($channel . ':out', '+', '-', 1))[0];

        expect((int) $last['generation'])->toBe((int) $thread->runner_generation)
            ->and((new E2eBrokerWire('threads'))->generation($channel))->toBe((int) $thread->runner_generation)
            ->and((int) $last['sequence'])->toBe((int) $thread->applied_sequence);
    }
});

it('makes the phones revocable on the web', function (): void {
    seededWorld();

    $this->actingAs(User::query()->firstOrFail());
    $version = (string) $this->get(route('settings.devices'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

    $names = $this->get(route('settings.devices'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Settings/Devices',
        'X-Inertia-Partial-Data' => 'devices',
    ])->assertOk()->json('props.devices.*.name');

    expect($names)->toContain('Test phone', 'Second test phone');
});
