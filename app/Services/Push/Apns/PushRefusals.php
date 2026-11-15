<?php

namespace App\Services\Push\Apns;

use App\Exceptions\ThreadActionRefused;

/**
 * The 409 answers of the push endpoints, each with a sentence a person can act on.
 */
final class PushRefusals
{
    public static function unavailable(): ThreadActionRefused
    {
        return ThreadActionRefused::because('push_unavailable', 'Notifications are not set up on this Yak. Ask your admin to add an APNs key.');
    }

    public static function environmentMismatch(string $expected, string $given): ThreadActionRefused
    {
        $message = $expected === 'sandbox'
            ? 'This Yak sends notifications to development builds only. Install a development build to get notifications.'
            : 'This Yak sends notifications to production builds only. Install the build from TestFlight to get notifications.';

        return ThreadActionRefused::because('push_environment_mismatch', $message, ['expected' => $expected, 'given' => $given]);
    }

    public static function noToken(): ThreadActionRefused
    {
        return ThreadActionRefused::because('no_push_token', "This device has not registered for notifications yet. Turn notifications on in the app's Settings.");
    }

    public static function rejected(string $reason): ThreadActionRefused
    {
        $message = match ($reason) {
            'BadDeviceToken' => 'Apple does not recognize this device. Turn notifications off and on again in the app, then retry.',
            'Unregistered' => 'This device no longer accepts notifications. Open the app and turn notifications on again.',
            'DeviceTokenNotForTopic' => 'This device registered for a different app build. Install the build from your admin and turn notifications on again.',
            'TopicDisallowed' => "Apple does not allow this app's bundle identifier for the key. Ask your admin to check the APNs settings.",
            'InvalidProviderToken', 'ExpiredProviderToken' => 'Apple refused this Yak\'s APNs key. Ask your admin to check the key, key ID and team ID.',
            'BadEnvironmentKeyInToken' => "This Yak's APNs key does not match its environment. Ask your admin to check the APNs settings.",
            'BroadcastFeatureNotEnabled' => 'Live Activities are not enabled for this APNs key. Ask your admin to enable broadcast push for the app.',
            default => "Apple refused the notification ({$reason}). Ask your admin to check the APNs settings.",
        };

        return ThreadActionRefused::because('push_rejected', $message, ['reason' => $reason]);
    }
}
