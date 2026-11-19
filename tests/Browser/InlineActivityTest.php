<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadEventPublisher;

test('a thread draws its tool calls with the same shared step rows', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Fix the login redirect']);
    $publisher = app(ThreadEventPublisher::class);
    $publisher->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => ['file_path' => 'app/Http/Controllers/LoginController.php']],
        ['type' => 'tool_use', 'id' => 't2', 'name' => 'Bash', 'input' => ['command' => 'php artisan test --compact']],
        ['type' => 'text', 'text' => 'Done reading'],
    ]]);
    $publisher->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => '<?php class LoginController {}']);
    $publisher->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't2', 'isError' => true, 'text' => 'Tests: 1 failed']);
    $this->actingAs($user);

    visit("/threads/{$thread->public_id}")->wait(1)
        ->assertVisible('[data-testid="thread-tool-activity"] [data-testid="step-group"]')
        ->assertSeeIn('[data-testid="thread-tool-activity"]', 'Ran 1 command, read 1 file')
        ->assertSeeIn('[data-testid="thread-tool-activity"]', '1 failed')
        ->click('[data-testid="thread-tool-activity"] [data-testid="step-group"] > summary')
        ->click('[data-testid="thread-tool-activity"] [data-testid="step-row"] > summary >> nth=0')
        ->assertSeeIn('[data-testid="step-detail"]', 'class LoginController')
        ->assertNoJavaScriptErrors();
});
