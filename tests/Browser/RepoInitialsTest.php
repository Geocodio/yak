<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;

it('renders the repo chip for single-segment and empty slugs', function (?string $slug, string $initials) {
    $user = User::factory()->create();
    if ($slug !== null) {
        Repository::factory()->create(['slug' => $slug, 'is_default' => true]);
    }
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('threads.new'));
    if ($initials !== '') {
        $page->assertSeeIn('[data-testid="start-thread-repo"] [data-testid="repo-avatar"]', $initials);
    }
    $page->assertNoJavaScriptErrors();
})->with([
    'two segments' => ['acme/web', 'WE'],
    'single segment' => ['solo', 'SO'],
    'no repository' => [null, ''],
]);
