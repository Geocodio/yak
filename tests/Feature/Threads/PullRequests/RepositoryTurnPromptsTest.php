<?php

use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\RepositoryTurnPrompts;

it('tells the agent to commit and leaves the push to Yak in the ci failure prompt', function () {
    $row = ThreadRepository::factory()->withPullRequest(4)->make(['repo' => 'acme/web', 'sandbox_path' => '/workspace/web']);

    $prompt = RepositoryTurnPrompts::ciFailure($row, 'boom');

    expect($prompt)->not->toContain('and push')
        ->toContain('Yak pushes the branch');
});

it('never tells the agent to push in the retry and follow-up prompts', function (string $view) {
    $contents = file_get_contents(resource_path("views/prompts/tasks/{$view}.blade.php"));

    expect($contents)->not->toContain('and push')
        ->not->toContain('push any code changes');
})->with(['retry', 'follow-up']);
