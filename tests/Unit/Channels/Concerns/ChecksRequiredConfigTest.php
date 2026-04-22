<?php

use App\Channels\Concerns\ChecksRequiredConfig;
use Tests\TestCase;

uses(TestCase::class);

class FakeChannelWithConfig
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'fake';
    }

    /** @return array<int, string> */
    public function requiredConfig(): array
    {
        return ['api_key', 'secret'];
    }
}

it('returns the channel config array', function (): void {
    config()->set('yak.channels.fake', ['api_key' => 'abc', 'secret' => 'def']);

    expect((new FakeChannelWithConfig)->config())->toBe(['api_key' => 'abc', 'secret' => 'def']);
});

it('returns an empty array when config is missing', function (): void {
    config()->set('yak.channels.fake', null);

    expect((new FakeChannelWithConfig)->config())->toBe([]);
});

it('is enabled when all required keys are present', function (): void {
    config()->set('yak.channels.fake', ['api_key' => 'abc', 'secret' => 'def']);

    expect((new FakeChannelWithConfig)->enabled())->toBeTrue();
});

it('is disabled when any required key is empty', function (): void {
    config()->set('yak.channels.fake', ['api_key' => 'abc', 'secret' => '']);

    expect((new FakeChannelWithConfig)->enabled())->toBeFalse();
});

it('is disabled when any required key is missing', function (): void {
    config()->set('yak.channels.fake', ['api_key' => 'abc']);

    expect((new FakeChannelWithConfig)->enabled())->toBeFalse();
});

it('is enabled when there are no required keys', function (): void {
    $channel = new class
    {
        use ChecksRequiredConfig;

        public function name(): string
        {
            return 'none';
        }

        public function requiredConfig(): array
        {
            return [];
        }
    };

    expect($channel->enabled())->toBeTrue();
});
