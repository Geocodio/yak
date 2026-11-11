<?php

use App\Models\Thread;
use App\Models\User;
use App\Services\Push\ThreadPresence;
use Pusher\Pusher;

function presenceWith(mixed $pusher): ThreadPresence
{
    return new class($pusher) extends ThreadPresence
    {
        public function __construct(private readonly mixed $fake) {}

        protected function pusher(): ?Pusher
        {
            return $this->fake;
        }
    };
}

function fakePusher(array|Throwable $response): Pusher
{
    $pusher = Mockery::mock(Pusher::class);
    $call = $pusher->shouldReceive('get');
    $response instanceof Throwable ? $call->andThrow($response) : $call->andReturn($response);

    return $pusher;
}

it('is viewing when the user is on the presence channel', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    expect(presenceWith(fakePusher(['users' => [['id' => $user->id]]]))->isViewing($user, $thread))->toBeTrue();
});

it('is not viewing when others only are present', function () {
    $thread = Thread::factory()->create();

    expect(presenceWith(fakePusher(['users' => [['id' => 999999]]]))->isViewing(User::factory()->create(), $thread))->toBeFalse();
});

it('counts an api error as not viewing', function () {
    expect(presenceWith(fakePusher(new RuntimeException('down')))->isViewing(User::factory()->create(), Thread::factory()->create()))->toBeFalse();
});

it('counts a non-pusher broadcaster as not viewing', function () {
    config(['broadcasting.default' => 'log']);

    expect(app(ThreadPresence::class)->isViewing(User::factory()->create(), Thread::factory()->create()))->toBeFalse();
});
