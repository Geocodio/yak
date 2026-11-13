<?php

use App\Enums\TaskMode;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadStarter;
use App\Support\AttachmentReferences;
use Illuminate\Support\Facades\Queue;

it('strips attachment tokens and tidies the whitespace', function (string $text, string $expected) {
    expect(AttachmentReferences::stripFromText($text))->toBe($expected);
})->with([
    ['Fix this tooltip [Image #1]', 'Fix this tooltip'],
    ['Fix [Image #1] this  [File #2] tooltip', 'Fix this tooltip'],
    ['No tokens [here]', 'No tokens [here]'],
]);

it('titles a thread without the attachment tokens', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $user = User::factory()->create();
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id]);
    config(['yak.default_model' => 'opus']);

    $started = app(ThreadStarter::class)->start($user, 'acme/widgets', TaskMode::Fix, "Fix this tooltip [Image #1]\nmore", null, null);
    $task = YakTask::factory()->create(['repo' => 'acme/widgets', 'description' => 'Fix the overlay [Image #1] please']);
    $fromTask = app(ThreadFactory::class)->forTask($task);

    expect($started->title)->toBe('Fix this tooltip')
        ->and($fromTask->title)->toBe('Fix the overlay please');
});
