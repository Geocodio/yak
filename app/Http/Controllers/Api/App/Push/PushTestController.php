<?php

namespace App\Http\Controllers\Api\App\Push;

use App\Enums\PushTokenKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\DevicePushToken;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Push\Apns\PushRefusals;
use App\Services\Threads\NeedsYouCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PushTestController extends Controller
{
    /**
     * Sends one test alert to this device's alert token and waits for Apple's answer (at most the client's 5 second budget). No toggle or presence check applies.
     */
    public function __invoke(Request $request, ApnsConfig $config, ApnsClient $client, ApnsPayloads $payloads, ApnsTokenFailures $failures): JsonResponse
    {
        $device = AuthenticateDevice::device($request);

        if (! $config->isConfigured()) {
            throw PushRefusals::unavailable();
        }

        $token = DevicePushToken::query()
            ->where('device_id', $device->id)
            ->where('kind', PushTokenKind::Alert->value)
            ->first() ?? throw PushRefusals::noToken();

        $push = [
            'title' => 'Yak test',
            'body' => 'Notifications work on this device.',
            'url' => '/settings/notifications',
            'tag' => 'test-push',
            'event' => 'test',
            'kind' => 'account',
            'id' => null,
        ];

        $response = $client->sendAlert(
            $token->token,
            $payloads->alert($push, NeedsYouCount::for($device->user()->firstOrFail())),
            ApnsPayloads::collapseId($push['tag']),
        );

        if ($response->isSuccess()) {
            $failures->record($token, $response);

            return response()->json(['status' => 'sent']);
        }

        $failures->record($token, $response);

        if ($response->isRetryable()) {
            throw new HttpException(503, 'Apple did not answer in time. Try again in a minute.');
        }

        throw PushRefusals::rejected($response->reason ?? (string) $response->status);
    }
}
