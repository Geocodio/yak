<?php

namespace App\Services\Push\Apns;

use InvalidArgumentException;

/**
 * Apple's push hosts per environment, pinned in one place.
 *
 * Source, read on 2026-11-16: Apple's "Sending broadcast push notification requests to APNs" and "Sending channel management requests to APNs" pages in the User Notifications documentation.
 * - Device and broadcast sends: HTTP/2 and TLS 1.2 or later to `api.push.apple.com:443` (production) or `api.sandbox.push.apple.com:443` (sandbox). A broadcast is `POST /4/broadcasts/apps/<bundle id>` with `apns-push-type: liveactivity` and `apns-channel-id`. The page's sandbox example shows the host header `api-broadcast.sandbox.push.apple.com`; the connection list on the same page names `api.sandbox.push.apple.com`, which is what is used here.
 * - Channel management: `api-manage-broadcast.push.apple.com:2196` (production) or `api-manage-broadcast.sandbox.push.apple.com:2195` (sandbox). Create is `POST /1/apps/<bundle id>/channels` with the JSON body `{"message-storage-policy": 1, "push-type": "LiveActivity"}`, and the new channel id comes back in the `apns-channel-id` response header. Delete is `DELETE /1/apps/<bundle id>/channels` with the `apns-channel-id` request header.
 * - Message storage policy 1 keeps the most recent message for a device that was offline.
 */
final class ApnsHosts
{
    public const MESSAGE_STORAGE_MOST_RECENT = 1;

    public static function device(string $environment): string
    {
        return self::validated($environment) === 'sandbox' ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
    }

    public static function broadcast(string $environment): string
    {
        return self::device($environment);
    }

    public static function channels(string $environment): string
    {
        return self::validated($environment) === 'sandbox'
            ? 'https://api-manage-broadcast.sandbox.push.apple.com:2195'
            : 'https://api-manage-broadcast.push.apple.com:2196';
    }

    private static function validated(string $environment): string
    {
        return in_array($environment, ['production', 'sandbox'], true)
            ? $environment
            : throw new InvalidArgumentException('Unknown APNs environment.');
    }
}
