<?php

namespace App\Services\Threads\Files;

use App\Support\ClientApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidFilePath extends RuntimeException
{
    /** The client APIs answer in their own error shape through the registered renderer. */
    public function render(Request $request): ?JsonResponse
    {
        if (ClientApi::for($request) !== null) {
            return null;
        }

        return response()->json(['message' => $this->getMessage()], 422);
    }
}
