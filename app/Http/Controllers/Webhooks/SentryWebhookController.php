<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentryWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.sentry.webhook_secret'),
            'Sentry-Hook-Signature',
        );

        return response()->json(['ok' => true]);
    }
}
