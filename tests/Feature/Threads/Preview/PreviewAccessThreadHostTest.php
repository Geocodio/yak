<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->world = VisibilityWorld::build();
    $this->host = app(ThreadPreviewHosts::class)->hostnameFor($this->world->thread);
    $this->access = app(PreviewAccess::class);
});

it('redeems a ticket for a thread host for a person who may see it', function () {
    ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);

    foreach ([$this->world->owner, $this->world->other] as $user) {
        expect($this->access->targetKind($this->host))->toBe('thread')
            ->and($this->access->redeemTicket($this->host, $this->access->mintTicket($this->host, $user)))->toBe($user->id);
    }
});

it('refuses a ticket for a thread host to a person who may not see the thread', function (string $stranger) {
    $user = $this->world->{$stranger};

    expect($this->access->redeemTicket($this->host, $this->access->mintTicket($this->host, $user)))->toBeNull()
        ->and($this->access->mayEnter($this->host, $user->id))->toBeFalse();
})->with(['other', 'admin']);

it('refuses after the share is withdrawn', function () {
    $share = ThreadShare::factory()->create(['thread_id' => $this->world->thread->id, 'user_id' => $this->world->other->id]);
    $cookie = previewCookie($this->host, $this->world->other->id);
    expect($this->access->verifyCookie($this->host, $cookie))->toBeTrue();

    $share->delete();

    expect($this->access->verifyCookie($this->host, $cookie))->toBeFalse()
        ->and($this->access->cookieIsHidden($this->host, $cookie))->toBeTrue();
});

it('refuses for a closed thread', function () {
    $this->world->thread->update(['status' => ThreadStatus::Closed]);

    expect($this->access->mayEnter($this->host, $this->world->owner->id))->toBeFalse();
});

it('refuses for a thread owned by a local machine', function () {
    $this->world->thread->update(['owner_side' => ThreadOwnerSide::Device]);

    expect($this->access->mayEnter($this->host, $this->world->owner->id))->toBeFalse();
});

it('keeps the old behaviour for a deployment host', function () {
    expect($this->access->targetKind(VisibilityWorld::HOSTNAME))->toBe('deployment')
        ->and($this->access->targetKind('t-nope000000.yak.example.com'))->toBeNull()
        ->and($this->access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->owner->id))->toBeTrue()
        ->and($this->access->mayEnter(VisibilityWorld::HOSTNAME, $this->world->other->id))->toBeFalse();
});

it('does not redeem a ticket for one thread host on another', function () {
    $second = Thread::factory()->create(['owner_user_id' => $this->world->owner->id]);
    $otherHost = app(ThreadPreviewHosts::class)->hostnameFor($second);

    expect($this->access->redeemTicket($otherHost, $this->access->mintTicket($this->host, $this->world->owner)))->toBeNull();
});
