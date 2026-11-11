<?php

namespace App\Http\Controllers\Threads;

use App\Channels\ChannelRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountPickerData;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewThreadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $repositories = Repository::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('slug')->get();

        $defaultRepo = $repositories->firstWhere('is_default', true) ?? ($repositories->count() === 1 ? $repositories->first() : null);

        return Inertia::render('Threads/New', [
            'repos' => $repositories->map(fn (Repository $repository): array => ['slug' => $repository->slug, 'name' => $repository->name])->values()->all(),
            'picker' => AccountPickerData::forUser($request->user()),
            'defaultRepo' => $defaultRepo?->slug,
            'emailToYakAddress' => app(ChannelRegistry::class)->for('email')?->enabled() === true
                ? (string) config('yak.channels.email.inbound_address')
                : null,
        ]);
    }
}
