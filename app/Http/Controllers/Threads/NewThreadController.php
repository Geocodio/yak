<?php

namespace App\Http\Controllers\Threads;

use App\Channels\ChannelRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\NewThreadData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewThreadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Threads/New', [
            ...NewThreadData::for($request->user()),
            'emailToYakAddress' => app(ChannelRegistry::class)->for('email')?->enabled() === true
                ? (string) config('yak.channels.email.inbound_address')
                : null,
        ]);
    }
}
