<?php

namespace App;

class Channel
{
    /**
     * Required credentials per channel driver.
     * A channel is enabled when all its required credentials are present.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_CREDENTIALS = [
        'slack' => ['bot_token', 'signing_secret'],
        'linear' => ['webhook_secret'],
        'sentry' => ['auth_token', 'webhook_secret', 'org_slug'],
        'drone' => ['url', 'token'],
        'github' => ['app_id', 'private_key', 'webhook_secret'],
    ];

    public function __construct(private readonly string $name) {}

    /**
     * Whether this channel is enabled (all required credentials are present).
     */
    public function enabled(): bool
    {
        $config = $this->config();
        $driver = $config['driver'] ?? $this->name;
        $required = self::REQUIRED_CREDENTIALS[$driver] ?? [];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the full configuration array for this channel.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed>|null */
        $config = config("yak.channels.{$this->name}");

        return $config ?? [];
    }

    /**
     * Get the driver name for this channel.
     */
    public function driver(): string
    {
        /** @var string */
        return $this->config()['driver'] ?? $this->name;
    }
}
