<?php

use App\Enums\RunnerState;
use App\Models\Artifact;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\ThreadArtifacts;
use App\Services\Threads\ThreadLiveQuestions;
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
        ->click('[data-testid="questions-next"]')
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

    $page = visit("/threads/{$thread->public_id}");

    // The question reaches the page through the events request, so the form is not there at load.
    waitUntilPresent($page, '[data-testid="questions-decide"]');
    $page->click('[data-testid="questions-decide"]');
    waitUntilPresent($page, '[data-testid="questions-form"]', isPresent: false);

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

/** Saves shared files on the thread's task, the way a collection leaves them, and keeps the sandbox out of the question. */
function sharedQuestionFiles(YakTask $task, array $names): void
{
    app()->instance(ThreadArtifacts::class, Mockery::spy(ThreadArtifacts::class));
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

    foreach ($names as $name) {
        Storage::disk('artifacts')->put("shared/{$name}", str_ends_with($name, '.html') ? '<!doctype html><p>Mockup</p>' : $png);
        Artifact::factory()->shared($name)->create(['yak_task_id' => $task->id]);
    }
}

it('shows context attachments at rest and hides them on request', function () {
    Storage::fake('artifacts');
    [$thread, , $task, $user] = threadWithRunningTurn();
    $this->actingAs($user);
    sharedQuestionFiles($task, ['flow.png']);
    questionLineFor($thread, [[
        'question' => "Which flow?\n\n![Flow today](flow.png)",
        'header' => 'Flow',
        'options' => [['label' => 'Mirror', 'description' => 'Same as owned'], ['label' => 'Strict', 'description' => 'Refuse']],
        'multiSelect' => false,
    ]]);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="question-context"] img[alt="Flow today"]')
        ->assertDontSee('![Flow today]')
        ->click('[data-testid="question-context-toggle"]')
        ->assertMissing('[data-testid="question-context"] img')
        ->assertNoJavaScriptErrors();
});

it('shows option attachments as cards in Claude\'s order', function () {
    Storage::fake('artifacts');
    [$thread, , $task, $user] = threadWithRunningTurn();
    $this->actingAs($user);
    sharedQuestionFiles($task, ['b.html', 'c.png']);
    questionLineFor($thread, [[
        'question' => 'Which approach?',
        'header' => 'Approach',
        'options' => [
            ['label' => 'Alpha', 'description' => 'No picture'],
            ['label' => 'Bravo', 'description' => 'A mockup', 'preview' => '![Bravo mockup](b.html)'],
            ['label' => 'Charlie', 'description' => 'A chart', 'preview' => '![Charlie chart](c.png)'],
        ],
        'multiSelect' => false,
    ]]);

    $page = visit("/threads/{$thread->public_id}")
        ->assertCount('[data-testid="question-option-card"]', 2)
        ->assertVisible('[data-testid="question-option-card"] iframe[sandbox="allow-scripts"]')
        ->assertVisible('[data-testid="question-option-card"] img[alt="Charlie chart"]');

    expect($page->script('[...document.querySelectorAll(\'[data-testid="question-q1"] [role="radio"]\')].map((el) => el.innerText.trim().split(String.fromCharCode(10))[0])'))->toBe(['Alpha', 'Bravo', 'Charlie', 'Other']);

    $page->click('[data-testid="question-q1"] [role="radio"] >> nth=1')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    $command = collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'answer_question');
    expect((array) $command['answers'])->toBe(['Which approach?' => 'Bravo']);
});

it('steps through several questions and keeps earlier answers', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="questions-stepper"]')
        ->assertMissing('[data-testid="question-q2"]')
        ->assertMissing('[data-testid="questions-back"]')
        ->click('[data-testid="question-q1"] [role="checkbox"] >> nth=0')
        ->click('[data-testid="questions-next"]')
        ->assertVisible('[data-testid="question-q2"]')
        ->assertMissing('[data-testid="question-q1"]')
        ->assertMissing('[data-testid="questions-next"]')
        ->click('[data-testid="question-q2"] [role="radio"] >> nth=0')
        ->click('[data-testid="questions-step-q1"]')
        ->assertAttribute('[data-testid="question-q1"] [role="checkbox"] >> nth=0', 'aria-checked', 'true')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    $command = collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'answer_question');

    expect(array_keys((array) $command['answers']))->toBe(['Which screens first?', 'Which width?'])
        ->and((array) $command['answers'])->not->toContain(ThreadLiveQuestions::NO_ANSWER);
});

it('shows a single question without a stepper', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="question-q1"]')
        ->assertMissing('[data-testid="questions-stepper"]')
        ->assertMissing('[data-testid="questions-next"]');
});
