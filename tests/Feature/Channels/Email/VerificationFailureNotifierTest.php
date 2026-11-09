<?php

use App\Channels\Email\InputDriver;
use App\Channels\Email\VerificationFailureNotifier;
use App\Models\User;
use App\Models\UserEmailAddress;
use App\Providers\ChannelServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    $this->user = User::factory()->create(['slack_user_id' => 'U123', 'direct_messages_enabled' => true]);
    $this->email = app(InputDriver::class)->parsePayload(postmarkPayload(['Subject' => 'Fwd: Budget alert'], ['X-Spam-Tests' => 'SPF_PASS']));
});

it('tells the owner in Slack that a forward from their address was ignored', function () {
    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'U123'
        && $request['text'] === 'An email claiming to be from mhansen@hey.com failed sender verification, so Yak ignored it. Subject: Fwd: Budget alert');
});

it('sends at most one message per address per hour', function () {
    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);
    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);

    Http::assertSentCount(1);

    $this->travel(61)->minutes();
    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);

    Http::assertSentCount(2);
});

it('sends nothing when the owner turned direct messages off or Slack is not set up', function (array $userAttributes, string $token) {
    config()->set('yak.channels.slack.bot_token', $token);
    $this->user->update($userAttributes);

    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);

    Http::assertNothingSent();
})->with([
    'direct messages off' => [['direct_messages_enabled' => false], 'xoxb-test'],
    'no bot token' => [[], ''],
]);

it('never throws when Slack fails', function () {
    Http::fake(['slack.com/*' => fn () => throw new RuntimeException('network down')]);

    app(VerificationFailureNotifier::class)->notify($this->user, $this->email);
})->throwsNoExceptions();

it('DMs the owner from the webhook when a known address fails DKIM, and sends no email', function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@geocodio.yak.build', 'require_dkim' => true]);
    (new ChannelServiceProvider(app()))->boot();
    Mail::fake();
    Queue::fake();
    UserEmailAddress::factory()->confirmed()->for($this->user)->create(['email' => 'mhansen@hey.com']);

    $this->withBasicAuth('yak', 'secret')
        ->postJson('/webhooks/email', postmarkPayload(headers: ['X-Spam-Tests' => 'SPF_PASS']))
        ->assertOk()->assertJson(['dropped' => 'dkim_failed']);

    Http::assertSent(fn (Request $request) => $request['channel'] === 'U123');
    Mail::assertNothingOutgoing();
});

it('sends no DM when the failing address belongs to nobody', function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@geocodio.yak.build', 'require_dkim' => true]);
    (new ChannelServiceProvider(app()))->boot();

    $this->withBasicAuth('yak', 'secret')
        ->postJson('/webhooks/email', postmarkPayload(['FromFull' => ['Email' => 'stranger@hey.com']], ['X-Spam-Tests' => 'SPF_PASS']))
        ->assertOk();

    Http::assertNothingSent();
});
