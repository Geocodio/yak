<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.github.webhook_secret'),
            'X-Hub-Signature-256',
        );

        return response()->json(['ok' => true]);
    }
}
