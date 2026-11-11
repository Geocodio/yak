<?php

use App\Http\Requests\Concerns\AcknowledgesPrivatePublish;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

test('a team thread needs no acknowledgement', function () {
    $thread = Thread::factory()->create();
    $guard = new ThreadPublishGuard;

    expect($guard->requiresAcknowledgement($thread))->toBeFalse();
    $guard->assertAcknowledged($thread, false);
});

test('a private thread and a private child need it', function () {
    $root = Thread::factory()->private()->create();
    $child = Thread::factory()->create(['parent_thread_id' => $root->id, 'root_thread_id' => $root->id]);
    $guard = new ThreadPublishGuard;

    expect($guard->requiresAcknowledgement($root))->toBeTrue()
        ->and($guard->requiresAcknowledgement($child->fresh()))->toBeTrue();
});

test('a shared-with-people thread still needs it', function () {
    $thread = Thread::factory()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id]);

    expect((new ThreadPublishGuard)->requiresAcknowledgement($thread))->toBeTrue();
});

test('acknowledged passes, missing throws a 422 with the message', function () {
    $thread = Thread::factory()->private()->create();
    $guard = new ThreadPublishGuard;

    $guard->assertAcknowledged($thread, true);

    try {
        $guard->assertAcknowledged($thread, false);
        $this->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422)
            ->and($exception->errors())->toBe(['acknowledged_private_publish' => [ThreadPublishGuard::MESSAGE]]);
    }
});

test('the request trait reads the boolean input', function () {
    $request = new class extends FormRequest
    {
        use AcknowledgesPrivatePublish;
    };

    expect($request->merge(['acknowledged_private_publish' => 'true'])->acknowledgedPrivatePublish())->toBeTrue()
        ->and((new $request)->acknowledgedPrivatePublish())->toBeFalse();
});
