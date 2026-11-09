<?php

namespace App\Channels\Email;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
