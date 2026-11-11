<?php

use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\RepositoryTurnPrompts;

test('the ci failure prompt leaves pushing to Yak', function () {
    $row = ThreadRepository::factory()->changes()->make(['repo' => 'acme/lib', 'sandbox_path' => '/workspace/extras/lib']);

    $prompt = RepositoryTurnPrompts::ciFailure($row, null);

    expect($prompt)->toContain('commit your fix; Yak pushes it')->not->toContain('push the branch');
});
