<?php

use App\Enums\ThreadKind;
use App\Exceptions\StackRuleViolation;
use App\Models\Thread;
use App\Services\Stacks\StackRules;

beforeEach(function () {
    $this->rules = new StackRules;
});

dataset('spawn violations', [
    'a child' => [fn () => Thread::factory()->child()->create(), 'Sub-threads cannot spawn their own sub-threads.'],
    'a closed thread' => [fn () => Thread::factory()->closed()->create(), 'closed'],
    'a device-owned thread' => [fn () => Thread::factory()->ownedByDevice()->create(), 'Teleport this session to Yak first.'],
    'a stopped stack' => [fn () => Thread::factory()->create(['stack_stopped_at' => now()]), 'stopped'],
]);

it('refuses to spawn', function (Closure $parent, string $message) {
    expect(fn () => $this->rules->assertMaySpawn($parent()))
        ->toThrow(StackRuleViolation::class, $message);
})->with('spawn violations');

it('lets an unattended thread with no driver spawn', function () {
    $parent = Thread::factory()->create(['owner_user_id' => null]);

    $this->rules->assertMaySpawn($parent);

    expect(true)->toBeTrue();
});

it('tells whether two threads are in the same stack', function () {
    $root = Thread::factory()->create();
    $a = Thread::factory()->child($root)->create();
    $b = Thread::factory()->child($root)->create();
    $other = Thread::factory()->create();
    $otherChild = Thread::factory()->child($other)->create();

    expect($this->rules->inSameStack($a, $b))->toBeTrue()
        ->and($this->rules->inSameStack($a, $root))->toBeTrue()
        ->and($this->rules->inSameStack($a, $otherChild))->toBeFalse()
        ->and($this->rules->inSameStack($root, $other))->toBeFalse();
});

it('resolves a target by name, parent or id inside the stack', function () {
    $root = Thread::factory()->create(['name' => 'root-thread']);
    $a = Thread::factory()->child($root)->create(['name' => 'alpha', 'kind' => ThreadKind::Research]);
    $b = Thread::factory()->child($root)->create(['name' => 'beta']);

    expect($this->rules->resolveTarget($a, 'beta')->is($b))->toBeTrue()
        ->and($this->rules->resolveTarget($a, 'parent')->is($root))->toBeTrue()
        ->and($this->rules->resolveTarget($a, (string) $b->id)->is($b))->toBeTrue()
        ->and($this->rules->resolveTarget($root, 'alpha')->is($a))->toBeTrue()
        ->and($this->rules->resolveTarget($a, 'root-thread')->is($root))->toBeTrue();
});

it('refuses a target outside the stack', function () {
    $root = Thread::factory()->create();
    $a = Thread::factory()->child($root)->create();
    $other = Thread::factory()->create(['name' => 'elsewhere']);
    $otherChild = Thread::factory()->child($other)->create(['name' => 'stranger']);

    foreach (['elsewhere', 'stranger', (string) $other->id, (string) $otherChild->id, 'nope'] as $target) {
        expect(fn () => $this->rules->resolveTarget($a, $target))->toThrow(StackRuleViolation::class);
    }
});

it('refuses a send outside the stack, to a closed thread or to itself', function () {
    $root = Thread::factory()->create();
    $a = Thread::factory()->child($root)->create();
    $closed = Thread::factory()->child($root)->closed()->create();
    $stranger = Thread::factory()->create();

    $this->rules->assertMaySend($a, $root);

    expect(fn () => $this->rules->assertMaySend($a, $stranger))->toThrow(StackRuleViolation::class)
        ->and(fn () => $this->rules->assertMaySend($a, $closed))->toThrow(StackRuleViolation::class)
        ->and(fn () => $this->rules->assertMaySend($a, $a))->toThrow(StackRuleViolation::class);
});
