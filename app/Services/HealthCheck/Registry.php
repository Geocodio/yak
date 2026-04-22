<?php

namespace App\Services\HealthCheck;

use App\Channels\ChannelRegistry;
use InvalidArgumentException;

class Registry
{
    /**
     * System-level checks. Channel checks now come from ChannelRegistry.
     *
     * @var array<string, class-string<HealthCheck>>
     */
    private const SYSTEM_CHECKS = [
        'queue-worker' => QueueWorkerCheck::class,
        'last-task-completed' => LastTaskCompletedCheck::class,
        'incus-daemon' => IncusDaemonCheck::class,
        'sandbox-base-template' => SandboxBaseTemplateCheck::class,
        'claude-cli' => ClaudeCliCheck::class,
        'claude-auth' => ClaudeAuthCheck::class,
        'repositories' => RepositoriesCheck::class,
        'webhook-signatures' => WebhookSignaturesCheck::class,
    ];

    public function __construct(private readonly ChannelRegistry $channels) {}

    /** @return list<HealthCheck> */
    public function all(): array
    {
        return array_merge(
            $this->forSection(HealthSection::System),
            $this->forSection(HealthSection::Channels),
        );
    }

    /** @return list<HealthCheck> */
    public function forSection(HealthSection $section): array
    {
        if ($section === HealthSection::System) {
            return array_values(array_map(fn (string $class): HealthCheck => app($class), self::SYSTEM_CHECKS));
        }

        $checks = [];
        foreach ($this->channels->enabled() as $channel) {
            foreach ($channel->healthChecks() as $check) {
                $checks[] = $check;
            }
        }

        return $checks;
    }

    public function get(string $id): HealthCheck
    {
        if (isset(self::SYSTEM_CHECKS[$id])) {
            return app(self::SYSTEM_CHECKS[$id]);
        }

        $channel = $this->channels->for($id);
        if ($channel !== null) {
            $checks = $channel->healthChecks();
            if ($checks !== []) {
                return $checks[0];
            }
        }

        throw new InvalidArgumentException("Unknown health check: {$id}");
    }

    public function nameFor(string $id): string
    {
        return $this->get($id)->name();
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_merge(
            array_keys(self::SYSTEM_CHECKS),
            array_keys($this->channels->all()),
        );
    }
}
