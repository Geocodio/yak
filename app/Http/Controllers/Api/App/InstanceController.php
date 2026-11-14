<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class InstanceController extends Controller
{
    /**
     * Public discovery: lets the app recognise a Yak and check its protocol before pairing. Nothing else is public.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'yak' => true,
            'name' => config('yak.instance_name') ?: config('app.name'),
            'api' => ['current' => (int) config('yak.app.protocol'), 'min' => (int) config('yak.app.min_protocol')],
            'pairing' => ['qr' => true, 'address' => true],
        ]);
    }
}
