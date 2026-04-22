<?php

namespace App\Channels\Concerns;

/**
 * Shared config helpers for Channel implementations. Consumers must expose
 * `name()` and `requiredConfig()` methods — see the Channel interface.
 *
 * @phpstan-ignore trait.unused
 */
trait ChecksRequiredConfig
{
    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed>|null $config */
        $config = config("yak.channels.{$this->name()}");

        return $config ?? [];
    }

    public function enabled(): bool
    {
        $config = $this->config();

        foreach ($this->requiredConfig() as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }
}
