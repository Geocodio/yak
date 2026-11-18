<?php

use App\Http\Resources\ThreadMessagesData;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Services\Threads\ThreadStarter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    Repository::factory()->inactive()->create(['slug' => 'acme/old']);
    Queue::fake();
});

it('starts a thread and redirects to it', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->post(route('threads.store'), [
        'repo' => 'acme/widgets', 'mode' => 'fix', 'description' => 'Fix the bug', 'claude_account_id' => $account->id, 'model' => 'haiku',
    ]);

    $thread = Thread::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('threads.show', $thread));
    expect($thread->claude_account_id)->toBe($account->id)->and($thread->model)->toBe('haiku');
});

it('stores the files of a new thread against its first task', function () {
    Storage::fake('artifacts');
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->actingAs($this->user)->post(route('threads.store'), [
        'repo' => 'acme/widgets', 'mode' => 'fix', 'description' => 'Fix the bug [Image #1]', 'claude_account_id' => $account->id,
        'attachments' => [UploadedFile::fake()->image('my shot?.png')],
        'attachment_refs' => ['Image #1'],
    ])->assertRedirect();

    $attachment = TaskAttachment::query()->sole();
    $task = Thread::query()->latest('id')->firstOrFail()->tasks()->firstOrFail();

    expect($attachment->yak_task_id)->toBe($task->id)
        ->and($attachment->original_name)->toBe(TaskAttachment::safeFilename('my shot?.png'))
        ->and($attachment->original_name)->not->toContain('?')
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->uploaded_by_user_id)->toBe($this->user->id)
        ->and($attachment->reference)->toBe('Image #1');
    Storage::disk('artifacts')->assertExists($attachment->disk_path);
});

it('rejects an inactive repo and a short description', function () {
    $this->actingAs($this->user)->post(route('threads.store'), ['repo' => 'acme/old', 'mode' => 'fix', 'description' => 'x'])
        ->assertSessionHasErrors(['repo', 'description']);
});

it('puts the no-account error on the account field', function () {
    $this->actingAs($this->user)->post(route('threads.store'), ['repo' => 'acme/widgets', 'mode' => 'research', 'description' => 'Look into it'])
        ->assertSessionHasErrors('claude_account_id');
    expect(Thread::query()->count())->toBe(0);
});

it('removes uploaded attachments when the start fails', function () {
    Storage::fake('artifacts');
    $starter = Mockery::mock(ThreadStarter::class);
    $starter->shouldReceive('start')->andThrow(new RuntimeException('boom'));
    app()->instance(ThreadStarter::class, $starter);

    $this->actingAs($this->user)->withoutExceptionHandling();
    expect(fn () => $this->post(route('threads.store'), [
        'repo' => 'acme/widgets', 'mode' => 'fix', 'description' => 'Fix the bug',
        'attachments' => [UploadedFile::fake()->image('shot.png')],
    ]))->toThrow(RuntimeException::class);

    expect(TaskAttachment::count())->toBe(0)->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('shows the first message as pending before the first turn writes its own', function () {
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    $this->actingAs($this->user)->post(route('threads.store'), [
        'repo' => 'acme/widgets', 'mode' => 'fix', 'description' => 'Fix the bug', 'claude_account_id' => $account->id,
    ]);
    $thread = Thread::query()->latest('id')->firstOrFail();

    $messages = ThreadMessagesData::for($thread);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toMatchArray(['id' => 'initial-message', 'uuid' => null, 'state' => 'pending', 'text' => 'Fix the bug', 'authorName' => $this->user->name]);

    $task = $thread->tasks()->firstOrFail();
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => 'Fix the bug']);

    expect(array_column(ThreadMessagesData::for($thread), 'id'))->not->toContain('initial-message')->toHaveCount(1);
});
