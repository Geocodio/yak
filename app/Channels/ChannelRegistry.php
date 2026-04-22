<?php

namespace App\Channels;

class ChannelRegistry
{
    /** @var array<string, Channel> */
    private array $channels = [];

    public function register(Channel $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function for(string $name): ?Channel
    {
        return $this->channels[$name] ?? null;
    }

    /** @return array<string, Channel> */
    public function all(): array
    {
        return $this->channels;
    }

    /** @return array<string, Channel> */
    public function enabled(): array
    {
        return array_filter($this->channels, fn (Channel $c) => $c->enabled());
    }
}
