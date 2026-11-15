<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadShareMode;
use App\Exceptions\StackRuleViolation;
use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackRules;
use App\Services\Threads\ThreadSharing;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->rules = new StackRules;
    config(['yak.threads.max_children' => 12]);
});

dataset('spawn refusals', [
    'a child' => [
        fn () => Thread::factory()->child()->create(),
        'sub_thread_cannot_spawn',
        'A sub-thread cannot start sub-threads of its own. Add it to the parent thread instead.',
        'Sub-threads cannot spawn their own sub-threads.',
    ],
    'a closed root' => [
        fn () => Thread::factory()->closed()->create(),
        'thread_closed',
        'This thread is closed, so it cannot start sub-threads.',
        'This thread is closed, so it cannot spawn sub-threads.',
    ],
    'a device-owned root' => [
        fn () => Thread::factory()->ownedByDevice()->create(),
        'thread_owned_by_device',
        'Your computer is working on this thread. Hand it back to Yak before adding a sub-thread.',
        'Teleport this session to Yak first.',
    ],
    'a stopped stack' => [
        fn () => Thread::factory()->create(['stack_stopped_at' => now()]),
        'stack_stopped',
        'This stack was stopped, so it cannot start sub-threads.',
        'This stack was stopped, so it cannot spawn sub-threads.',
    ],
    'a full stack' => [
        function () {
            $root = Thread::factory()->create();
            Thread::factory()->child($root)->count(12)->create();

            return $root;
        },
        'stack_full',
        'This thread has 12 of 12 sub-threads. Close one to add another.',
        'A thread can have at most 12 sub-threads.',
    ],
]);

it('gives each spawn refusal a code and a sentence for a person', function (Closure $makeParent, string $code, string $message, string $claudeMessage) {
    $parent = $makeParent();
    $refusal = $this->rules->refusalForSpawn($parent, User::factory()->create());

    expect($refusal)->toBeInstanceOf(ThreadActionRefused::class)
        ->and($refusal->errorCode)->toBe($code)
        ->and($refusal->getMessage())->toBe($message)
        ->and($refusal->status)->toBe(409);

    if ($code === 'sub_thread_cannot_spawn') {
        expect($refusal->details)->toBe(['parentId' => $parent->public_id]);
    } elseif ($code === 'stack_full') {
        expect($refusal->details)->toBe(['count' => 12, 'max' => 12]);
    } else {
        expect($refusal->details)->toBe([]);
    }

    expect(fn () => $this->rules->assertMaySpawn($parent))->toThrow(StackRuleViolation::class, $claudeMessage);
})->with('spawn refusals');

it('refuses a person who cannot drive before any other rule', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create(['stack_stopped_at' => now()]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($root, $owner, ThreadShareMode::People, [$recipient->id]);

    $refusal = $this->rules->refusalForSpawn($root, $recipient);

    expect($refusal->errorCode)->toBe('forbidden')
        ->and($refusal->getMessage())->toBe('You do not have access to this.')
        ->and($refusal->status)->toBe(403)
        ->and($refusal->details)->toBe([])
        ->and($this->rules->refusalForSpawn($root, null)->errorCode)->toBe('forbidden');
});

it('allows an open root with room', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();

    expect($this->rules->refusalForSpawn($root, $owner))->toBeNull();
});

it('counts every child toward the cap', function () {
    $root = Thread::factory()->create();
    Thread::factory()->child($root)->count(11)->create();
    Thread::factory()->child($root)->closed()->create();

    expect($this->rules->refusalForSpawn($root, User::factory()->create())->errorCode)->toBe('stack_full');
});

it('converts a violation without a code only by mistake', function () {
    expect(fn () => (new StackRuleViolation('x'))->toRefusal())->toThrow(LogicException::class);

    $exception = (new StackRuleViolation('x'))->toValidationException();

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toBe(['stack' => ['Yak could not add this sub-thread. Try again.']]);
});

it('gives each repository refusal a field and a sentence for a person', function (ThreadKind $kind, string $repo, string $claudeMessage, string $personMessage) {
    $parent = Thread::factory()->create();

    try {
        $this->rules->resolveChildRepo($parent, $kind, $repo);
        $this->fail('Expected a StackRuleViolation.');
    } catch (StackRuleViolation $violation) {
        expect($violation->field)->toBe('repo')
            ->and($violation->errorCode)->toBeNull()
            ->and($violation->getMessage())->toBe($claudeMessage)
            ->and($violation->personMessage)->toBe($personMessage);
    }
})->with([
    'research' => [ThreadKind::Research, 'example/web', 'Research sub-threads see every repo of the parent; leave repo out.', 'Leave the repository out for a research sub-thread.'],
    'general' => [ThreadKind::General, 'example/web', 'Only implement sub-threads take a repo.', 'Only an implement sub-thread works in a repository of its own.'],
    'unknown repository' => [ThreadKind::Implement, 'example/missing', 'Yak cannot reach example/missing. Add the repository to the Yak GitHub app first.', 'Yak cannot reach example/missing. Ask an admin to add the repository to Yak\'s GitHub app.'],
]);
