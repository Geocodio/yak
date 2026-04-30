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

        return $this->channelChecks();
    }

    public function get(string $id): HealthCheck
    {
        if (isset(self::SYSTEM_CHECKS[$id])) {
            return app(self::SYSTEM_CHECKS[$id]);
        }

        foreach ($this->channelChecks() as $check) {
            if ($check->id() === $id) {
                return $check;
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
            array_map(fn (HealthCheck $c): string => $c->id(), $this->channelChecks()),
        );
    }

    /**
     * Flat list of all channel-supplied checks across enabled channels.
     * A single channel may expose more than one check (Slack ships
     * `slack` for the bot connection and `slack-interactivity` for the
     * Interactivity URL heuristic, for example).
     *
     * @return list<HealthCheck>
     */
    private function channelChecks(): array
    {
        $checks = [];
        foreach ($this->channels->all() as $channel) {
            if (! $channel->enabled()) {
                continue;
            }

            foreach ($channel->healthChecks() as $check) {
                $checks[] = $check;
            }
        }

        return $checks;
    }
}
