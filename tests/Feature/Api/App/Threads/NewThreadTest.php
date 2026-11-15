<?php

use App\DataTransferObjects\RepositoryAccessResult;
use App\DataTransferObjects\ResolvedAccount;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\Repos\RepositoryAccess;
use App\Services\Threads\ThreadStarter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContractFixture;

const NEW_THREAD_SEND_KEY = '5f0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f';

beforeEach(function () {
    Queue::fake();
    Storage::fake('artifacts');
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user) + ['Accept' => 'application/json'];
    Repository::factory()->create(['slug' => 'example/api', 'name' => 'API']);
});

/** @return array<string, mixed> */
function newThreadPayload(array $overrides = []): array
{
    return $overrides + [
        'repo' => 'example/api',
        'mode' => 'fix',
        'description' => 'Retry failed uploads with backoff',
        'send_key' => NEW_THREAD_SEND_KEY,
    ];
}

function usableAccountFor(User $user): void
{
    $resolver = Mockery::mock(ClaudeAccountResolver::class);
    $resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(ClaudeAccount::factory()->create(['owner_user_id' => $user->id]), 'sonnet'));
    app()->instance(ClaudeAccountResolver::class, $resolver);
}

it('offers repositories, accounts and models', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));

    $response = $this->getJson('/api/app/v1/threads/new', $this->headers)->assertOk();

    expect($response->json('repos.0.slug'))->toBe('example/api')
        ->and($response->json('picker'))->not->toBeNull()
        ->and($response->json('defaultRepo'))->toBe('example/api')
        ->and($response->json('models'))->toBe(app(ClaudeModelCatalog::class)->families())
        ->and($response->json('models'))->toBe($response->json('picker.models'));
    ContractFixture::assertMatches('app', 'thread-new', $response->json());
});

it('checks repository access', function () {
    $access = Mockery::mock(RepositoryAccess::class);
    $access->shouldReceive('check')->with('example/web')->andReturn(new RepositoryAccessResult(true, null));
    app()->instance(RepositoryAccess::class, $access);

    $response = $this->getJson('/api/app/v1/threads/new/repository-access?repo=example/web', $this->headers)->assertOk()
        ->assertJson(['allowed' => true, 'reason' => null]);
    ContractFixture::assertMatches('app', 'thread-repository-access', $response->json());

    $this->getJson('/api/app/v1/threads/new/repository-access?repo=not a repo', $this->headers)->assertStatus(422);
});

it('starts a thread and returns its public id', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    usableAccountFor($this->user);

    $response = $this->post('/api/app/v1/threads', newThreadPayload([
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #1'],
    ]), $this->headers)->assertCreated();

    $id = $response->json('id');
    $thread = Thread::where('public_id', $id)->firstOrFail();
    expect($id)->toHaveLength(8)
        ->and($thread->headTask()->attachments)->toHaveCount(1);
    ContractFixture::assertMatches('app', 'thread-store', $response->json(), ['id']);
});

it('starts one thread for a repeated send key', function () {
    usableAccountFor($this->user);

    $first = $this->postJson('/api/app/v1/threads', newThreadPayload(), $this->headers)->assertCreated();
    $second = $this->postJson('/api/app/v1/threads', newThreadPayload(), $this->headers)->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))->and(Thread::count())->toBe(1);
});

it('removes stored files when the start fails', function () {
    $starter = Mockery::mock(ThreadStarter::class);
    $starter->shouldReceive('start')->andThrow(new RuntimeException('secret internal detail'));
    app()->instance(ThreadStarter::class, $starter);

    $response = $this->post('/api/app/v1/threads', newThreadPayload(['attachments' => [UploadedFile::fake()->image('shot.png')]]), $this->headers)
        ->assertStatus(500)->assertJsonPath('error', 'server_error');

    expect($response->getContent())->not->toContain('secret internal detail')
        ->and(TaskAttachment::count())->toBe(0)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('turns an account failure into a field error', function () {
    $resolver = Mockery::mock(ClaudeAccountResolver::class);
    $resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(null, 'sonnet'));
    app()->instance(ClaudeAccountResolver::class, $resolver);

    $this->postJson('/api/app/v1/threads', newThreadPayload(), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['claude_account_id']]]);
    expect(Thread::count())->toBe(0);
});

it('validates the start', function (array $overrides, string $field) {
    $payload = newThreadPayload($overrides);

    if ($field === 'send_key') {
        unset($payload['send_key']);
    }

    $this->postJson('/api/app/v1/threads', $payload, $this->headers)
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    'missing send key' => [[], 'send_key'],
    'bad mode' => [['mode' => 'deploy'], 'mode'],
    'short description' => [['description' => 'x'], 'description'],
    'too many repositories' => [['repositories' => array_map(fn (int $i): array => ['repo' => "example/extra-{$i}", 'role' => 'context'], range(1, 11))], 'repositories'],
    'inactive repository' => [['repo' => 'example/missing'], 'repo'],
]);

it('routes threads/new before the thread binding', function () {
    $this->getJson('/api/app/v1/threads/new', $this->headers)->assertOk();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $this->getJson('/api/app/v1/threads/new', ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    $this->getJson('/api/app/v1/threads/new', $headers)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->user->id], false);
    $this->getJson('/api/app/v1/threads/new', $computerHeaders)->assertUnauthorized();
});
