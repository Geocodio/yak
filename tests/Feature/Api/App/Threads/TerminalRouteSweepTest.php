<?php

use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSharing;
use Tests\Support\VisibilityRoutes;

require_once __DIR__ . '/ThreadRouteSweepTest.php';

it('never wakes or writes for a share recipient', function (string $name) {
    ['thread' => $thread, 'owner' => $owner, 'placeholders' => $placeholders] = sweepThread();
    $thread->update(['sandbox_state' => ThreadSandboxState::Paused]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $sandboxes = app(SandboxManager::class);
    $callsBefore = count($sandboxes->calls);

    appRouteSweepRequest($this, VisibilityRoutes::appThreadProbes()[$name], $thread->public_id, phoneHeadersFor($recipient), $placeholders);

    $wakesAndWrites = collect(array_slice($sandboxes->calls, $callsBefore))
        ->filter(fn (array $call): bool => in_array($call['operation'], ['resume', 'start', 'create', 'destroy', 'stop'], true)
            || ($call['operation'] === 'exec' && (($call['arguments']['stdin'] ?? null) !== null || str_contains((string) end($call['arguments']['command']), 'mv '))));

    expect($wakesAndWrites)->toBeEmpty()
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
})->with(array_keys(array_filter(VisibilityRoutes::appThreadProbes(), fn (string $name): bool => str_contains($name, 'terminal') || str_contains($name, 'files'), ARRAY_FILTER_USE_KEY)));
