<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.slack.signing_secret'),
            'X-Slack-Signature',
            prefix: 'v0=',
        );

        return response()->json(['ok' => true]);
    }
}
