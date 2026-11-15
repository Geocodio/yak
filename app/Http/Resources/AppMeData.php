<?php

namespace App\Http\Resources;

use App\Http\Controllers\Threads\TerminalSessionController;
use App\Models\Device;
use App\Models\User;
use App\Services\Threads\ThreadList;

/**
 * What the app needs at launch and when it switches instance. The push block carries the bundle id and environment, never the key.
 */
final class AppMeData
{
    /**
     * @return array{
     *     user: array{id: int, name: string, email: string, initials: string, isAdmin: bool},
     *     device: array{id: int, name: string, kind: string, pairedAt: ?string},
     *     instance: array{name: string, url: string, version: ?string},
     *     needsYouCount: int,
     *     realtime: ?array{key: string, host: string, port: int, scheme: string},
     *     terminal: array{wsUrl: string},
     *     attachments: array{maxFiles: int, maxFileBytes: int},
     *     push: array{apns: bool, bundleId: ?string, environment: ?string},
     *     features: array{liveActivities: bool, widgets: bool}
     * }
     */
    public static function from(User $user, Device $device): array
    {
        $apns = (array) config('yak.apns');
        $isApnsConfigured = collect(['key', 'key_id', 'team_id', 'bundle_id'])->every(fn (string $field): bool => ($apns[$field] ?? null) !== null && $apns[$field] !== '');

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials(),
                'isAdmin' => $user->isAdmin(),
            ],
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'kind' => $device->kind->value,
                'pairedAt' => $device->created_at?->toIso8601String(),
            ],
            'instance' => [
                'name' => (string) (config('yak.instance_name') ?: config('app.name')),
                'url' => rtrim((string) config('app.url'), '/'),
                'version' => config('app.version'),
            ],
            'needsYouCount' => ThreadList::for($user)['needsYouCount'],
            'realtime' => self::realtime(),
            'terminal' => ['wsUrl' => TerminalSessionController::socketUrl()],
            'attachments' => [
                'maxFiles' => (int) config('yak.attachments.max_files'),
                'maxFileBytes' => (int) config('yak.attachments.max_file_kb') * 1024,
            ],
            'push' => [
                'apns' => $isApnsConfigured,
                'bundleId' => $apns['bundle_id'] ?? null,
                'environment' => $apns['environment'] ?? null,
            ],
            'features' => ['liveActivities' => $isApnsConfigured, 'widgets' => true],
        ];
    }

    /**
     * @return ?array{key: string, host: string, port: int, scheme: string}
     */
    private static function realtime(): ?array
    {
        $reverb = (array) config('broadcasting.connections.reverb');
        $options = (array) ($reverb['options'] ?? []);

        if (config('broadcasting.default') !== 'reverb' || empty($reverb['key']) || empty($options['host'])) {
            return null;
        }

        return [
            'key' => (string) $reverb['key'],
            'host' => (string) $options['host'],
            'port' => (int) ($options['port'] ?? 443),
            'scheme' => (string) ($options['scheme'] ?? 'https'),
        ];
    }
}
