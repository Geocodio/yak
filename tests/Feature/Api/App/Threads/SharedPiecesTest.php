<?php

use App\Enums\ThreadShareMode;
use App\Exceptions\ThreadActionRefused;
use App\Http\Middleware\ClientProtocol;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Illuminate\Support\Facades\Route;
use Tests\Support\ContractFixture;

beforeEach(function () {
    Route::middleware(['web', 'client.protocol:app', 'auth.device:phone'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::post('/api/app/v1/test-refuse', fn () => throw ThreadActionRefused::because('thread_blocked', 'This thread has not started yet. It starts when its repository is set up.', ['reason' => 'waiting_for_repository']));
        Route::post('/api/app/v1/test-closed', fn () => throw ThreadActionRefused::because('thread_closed', 'This thread is closed.'));
        Route::post('/api/app/v1/test-drive/{thread}', fn (Thread $thread) => response()->json(['status' => 'ok']))->middleware('thread.drive');
        Route::get('/api/app/v1/test-thread/{thread}', fn (Thread $thread) => response()->json(['id' => $thread->public_id]));
    });
});

it('renders a refusal as 409 with its code, message and details', function () {
    [, $headers] = phoneWithHeaders();

    $response = $this->postJson('/api/app/v1/test-refuse', [], $headers)->assertStatus(409)
        ->assertExactJson(['error' => 'thread_blocked', 'message' => 'This thread has not started yet. It starts when its repository is set up.', 'details' => ['reason' => 'waiting_for_repository']]);
    ContractFixture::assertMatches('app', 'error-thread-blocked', $response->json());

    $this->postJson('/api/app/v1/test-closed', [], $headers)->assertStatus(409)->assertExactJson(['error' => 'thread_closed', 'message' => 'This thread is closed.']);
});

it('answers 404 for a hidden thread and 403 for a share recipient on a drive route', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson("/api/app/v1/test-drive/{$thread->public_id}", [], phoneHeadersFor(User::factory()->create()))->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
    $this->postJson("/api/app/v1/test-drive/{$thread->public_id}", [], phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->postJson("/api/app/v1/test-drive/{$thread->public_id}", [], phoneHeadersFor($owner))->assertOk();
});

it('binds threads by public id only on the app API', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $owner->id]);

    $this->getJson("/api/app/v1/test-thread/{$thread->id}", phoneHeadersFor($owner))->assertNotFound()->assertJsonPath('error', 'not_found');
    $this->getJson("/api/app/v1/test-thread/{$thread->public_id}", phoneHeadersFor($owner))->assertOk()->assertJson(['id' => $thread->public_id]);
});

it('binds a public id made only of digits on the app API', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $owner->id, 'public_id' => '12345678']);

    $this->getJson('/api/app/v1/test-thread/12345678', phoneHeadersFor($owner))->assertOk()->assertJson(['id' => '12345678']);
    $this->postJson('/api/app/v1/test-drive/12345678', [], phoneHeadersFor($owner))->assertOk();
    $this->getJson("/api/app/v1/test-thread/{$thread->id}", phoneHeadersFor($owner))->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('names the head task and the owner of pending messages', function () {
    $thread = Thread::factory()->create();
    expect($thread->headTask())->toBeNull();

    $first = YakTask::factory()->create(['thread_id' => $thread->id]);
    $second = YakTask::factory()->create(['thread_id' => $thread->id]);

    expect($thread->headTask()->is($second))->toBeTrue()
        ->and($thread->messagesRootTask()->is($first->rootTask()))->toBeTrue();
});
