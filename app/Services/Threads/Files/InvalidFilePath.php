<?php

namespace App\Services\Threads\Files;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidFilePath extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
