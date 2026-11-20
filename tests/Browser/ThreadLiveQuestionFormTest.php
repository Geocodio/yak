<?php

use App\Enums\RunnerState;
use App\Models\PendingSteeringMessage;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ParseMultipartBody;

require_once __DIR__ . '/../Support/LiveQuestionHelpers.php';

beforeEach(function () {
    app()->singleton(BrokerStreams::class, FakeBrokerStreams::class);
});

it('shows Claude\'s question mid-turn and sends the answer to the runner', function () {
    [$thread, $run, $task, $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->assertSeeIn('[data-testid="questions-live"]', 'Claude is waiting for your answer · 2 questions')
        ->assertSeeIn('[data-testid="questions-form"]', 'Which screens first?')
        ->assertSeeIn('[data-testid="questions-form"]', 'Let Claude decide')
        ->assertMissing('[data-testid="questions-note"]')
        ->assertDisabled('[data-testid="questions-submit"]')
        ->click('[data-testid="question-q1"] [role="checkbox"] >> nth=0')
        ->click('[data-testid="question-q1"] [role="checkbox"] >> nth=1')
        ->click('[data-testid="question-q2"] >> text=Other')
        ->fill('[data-testid="question-q2-other"]', 'Both, desktop first')
        ->assertSee('2 of 2 answered')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    $command = collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'answer_question');

    expect((array) $command['answers'])->toBe(['Which screens first?' => 'Contacts, Deals', 'Which width?' => 'Both, desktop first'])
        ->and($task->fresh()->liveClarificationRound())->toBeNull();
});

it('lets Claude decide from the live question form', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="questions-decide"]')
        ->assertMissing('[data-testid="questions-form"]');

    expect(collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'decline_question')['message'])->toBe('The person asked you to decide.');
});

it('fits the live question form at phone width', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    $page = visit("/threads/{$thread->public_id}")->on()->mobile()
        ->assertVisible('[data-testid="questions-live"]')
        ->assertVisible('[data-testid="questions-decide"]')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('shows the files of an answer as a queued message when Claude cannot take them now', function () {
    Storage::fake('artifacts');
    app(HttpKernel::class)->prependMiddleware(ParseMultipartBody::class);
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [widthQuestion()]);
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $file = tempnam(sys_get_temp_dir(), 'yak') . '.png';
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    $page = visit("/threads/{$thread->public_id}");
    $page->attach('[data-testid="attachment-input"]', $file)
        ->assertCount('[data-testid="draft-attachment"]', 1)
        ->typeSlowly('[data-testid="thread-composer-input"]', ' laptop', 5)
        ->assertValue('[data-testid="thread-composer-input"]', '[Image #1]  laptop')
        ->click('[data-testid="thread-send"]');

    $page->waitForText('Your files go to Claude with the next message.');

    $queued = PendingSteeringMessage::query()->sole();

    $page->assertSeeIn('[data-testid="queued-message-' . $queued->id . '"]', 'laptop')
        ->assertSeeIn('[data-testid="queued-message-' . $queued->id . '"]', 'Queued')
        ->assertPresent('[data-testid="queued-message-' . $queued->id . '"] [aria-label="1 attached"]')
        ->assertSee('Your files go to Claude with the next message.')
        ->assertNoJavaScriptErrors();
});
