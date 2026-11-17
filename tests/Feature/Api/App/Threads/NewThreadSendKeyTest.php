<?php

use App\DataTransferObjects\ResolvedAccount;
use App\Enums\TaskMode;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Services\AgentJobDispatcher;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\Repos\RepositoryAccess;
use App\Services\Threads\ThreadStarter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContractFixture;

const SEND_KEY_TEST_KEY = '5f0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f';

beforeEach(function () {
    Queue::fake();
    Storage::fake('artifacts');
    Repository::factory()->create(['slug' => 'example/api', 'name' => 'API']);
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user) + ['Accept' => 'application/json'];
    $resolver = Mockery::mock(ClaudeAccountResolver::class);
    $resolver->shouldReceive('forNewThread')->andReturnUsing(
        fn (User $starter) => new ResolvedAccount(ClaudeAccount::factory()->create(['owner_user_id' => $starter->id]), 'sonnet'),
    );
    app()->instance(ClaudeAccountResolver::class, $resolver);
});

/** @return array<string, string> */
function sendKeyPayload(string $sendKey = SEND_KEY_TEST_KEY): array
{
    return ['repo' => 'example/api', 'mode' => 'fix', 'description' => 'Retry failed uploads', 'send_key' => $sendKey];
}

it('returns the same thread for a repeated send_key a day later', function () {
    $first = $this->postJson('/api/app/v1/threads', sendKeyPayload(), $this->headers)->assertCreated();

    $this->travel(24)->hours();
    Cache::flush();

    $repeat = $this->postJson('/api/app/v1/threads', sendKeyPayload(), $this->headers)->assertCreated();

    expect($repeat->json('id'))->toBe($first->json('id'))
        ->and(Thread::query()->withoutGlobalScopes()->where('send_key', SEND_KEY_TEST_KEY)->count())->toBe(1);
    ContractFixture::assertMatches('app', 'thread-store', $repeat->json(), ['id']);
});

it('starts one thread for two requests at once', function () {
    $user = $this->user;
    $winner = null;
    app()->instance(ThreadStarter::class, new class(app(ClaudeAccountResolver::class), app(AgentJobDispatcher::class), app(RepositoryAccess::class), function () use ($user, &$winner) {
        $winner = Thread::factory()->create(['created_by_user_id' => $user->id, 'send_key' => SEND_KEY_TEST_KEY]);
    }) extends ThreadStarter {
        public function __construct(ClaudeAccountResolver $resolver, AgentJobDispatcher $dispatcher, RepositoryAccess $access, private readonly Closure $beforeStart)
        {
            parent::__construct($resolver, $dispatcher, $access);
        }

        public function start(User $starter, string $repo, TaskMode $mode, string $description, ?int $accountId, ?string $model, Collection $attachments = new Collection, array $extraRepositories = [], ?string $sendKey = null, ?string $effort = null): Thread
        {
            ($this->beforeStart)();

            return parent::start($starter, $repo, $mode, $description, $accountId, $model, $attachments, $extraRepositories, $sendKey, $effort);
        }
    });

    $response = $this->post('/api/app/v1/threads', sendKeyPayload() + ['attachments' => [UploadedFile::fake()->image('shot.png')]], $this->headers)->assertCreated();

    expect($response->json('id'))->toBe($winner->public_id)
        ->and(Thread::query()->withoutGlobalScopes()->where('send_key', SEND_KEY_TEST_KEY)->count())->toBe(1)
        ->and(TaskAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('lets another person use the same key', function () {
    $this->postJson('/api/app/v1/threads', sendKeyPayload(), $this->headers)->assertCreated();

    $other = User::factory()->create(['name' => 'Other person']);
    $this->postJson('/api/app/v1/threads', sendKeyPayload(), phoneHeadersFor($other) + ['Accept' => 'application/json'])->assertCreated();

    expect(Thread::query()->withoutGlobalScopes()->where('send_key', SEND_KEY_TEST_KEY)->count())->toBe(2);
});

it('refuses a send_key longer than 36 characters', function () {
    $this->postJson('/api/app/v1/threads', sendKeyPayload(str_repeat('a', 37)), $this->headers)
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed');

    expect(Thread::query()->withoutGlobalScopes()->count())->toBe(0);
});
