<?php

use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Support\ChannelCard;

it('shows a Slack reply in a card that links back to it, with mentions marked', function () {
    $root = YakTask::factory()->success()->create(['source' => 'slack']);
    $card = ['channel' => 'slack', 'url' => 'https://acme.slack.com/archives/C1/p1', 'reference' => '#eng-support', 'title' => null, 'avatarUrl' => 'https://avatars.slack-edge.com/a.png', 'mentions' => ['Mathias Hansen']];
    $steer = PendingSteeringMessage::queueFor($root, '@Mathias Hansen can you check the CSV path?', 'slack', authorName: 'Sarah Chen', channelCard: $card);
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $root->thread_id]);
    PendingSteeringMessage::drain($root, collect([$steer]), $run);

    $message = collect(ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id)))->firstWhere('authorName', 'Sarah Chen');

    expect($message['channelCard'])->toBe($card)
        ->and($message['via']['url'])->toBe('https://acme.slack.com/archives/C1/p1')
        ->and($message['bodyHtml'])->toContain('<mark data-mention>@Mathias Hansen</mark> can you check');
});

it('shows the request that started a thread in its channel card', function () {
    $root = YakTask::factory()->success()->create(['source' => 'linear', 'channel_card' => ChannelCard::linearIssue('ENG-42', 'Fix the importer', 'https://linear.app/acme/issue/ENG-42')]);
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $root->thread_id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => 'Fix the importer']);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id))[0]['channelCard'])->toMatchArray(['channel' => 'linear', 'reference' => 'ENG-42', 'title' => 'Fix the importer']);
});

it('gives a message from the dashboard no card', function () {
    $root = YakTask::factory()->success()->create();
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $root->thread_id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => 'hello']);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id))[0]['channelCard'])->toBeNull();
});
