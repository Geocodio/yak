<?php

namespace App\Services\HealthCheck;

use App\Channel;
use App\Channels\Drone\HealthCheck as DroneChannelCheck;
use App\Channels\Sentry\HealthCheck as SentryChannelCheck;
use App\Services\HealthCheck\Channel\GitHubChannelCheck;
use App\Services\HealthCheck\Channel\LinearChannelCheck;
use App\Services\HealthCheck\Channel\SlackChannelCheck;
use InvalidArgumentException;

class Registry
{
    /**
     * Ordered list of every check we know about. System checks always run;
     * channel checks only appear when the channel is enabled.
     *
     * @var array<string, class-string<HealthCheck>>
     */
    private const CHECKS = [
        'queue-worker' => QueueWorkerCheck::class,
        'last-task-completed' => LastTaskCompletedCheck::class,
        'incus-daemon' => IncusDaemonCheck::class,
        'sandbox-base-template' => SandboxBaseTemplateCheck::class,
        'claude-cli' => ClaudeCliCheck::class,
        'claude-auth' => ClaudeAuthCheck::class,
        'repositories' => RepositoriesCheck::class,
        'webhook-signatures' => WebhookSignaturesCheck::class,
        'slack' => SlackChannelCheck::class,
        'linear' => LinearChannelCheck::class,
        'sentry' => SentryChannelCheck::class,
        'github' => GitHubChannelCheck::class,
        'drone' => DroneChannelCheck::class,
    ];

    /**
     * @return list<HealthCheck>
     */
    public function all(): array
    {
        return array_merge(
            $this->forSection(HealthSection::System),
            $this->forSection(HealthSection::Channels),
        );
    }

    /**
     * @return list<HealthCheck>
     */
    public function forSection(HealthSection $section): array
    {
        $checks = [];

        foreach (self::CHECKS as $id => $class) {
            $check = app($class);

            if ($check->section() !== $section) {
                continue;
            }

            if ($section === HealthSection::Channels && ! (new Channel($id))->enabled()) {
                continue;
            }

            $checks[] = $check;
        }

        return $checks;
    }

    public function get(string $id): HealthCheck
    {
        if (! isset(self::CHECKS[$id])) {
            throw new InvalidArgumentException("Unknown health check: {$id}");
        }

        return app(self::CHECKS[$id]);
    }

    public function nameFor(string $id): string
    {
        return $this->get($id)->name();
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys(self::CHECKS);
    }
}
