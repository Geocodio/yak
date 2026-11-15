<?php

use App\Services\Threads\SendKeys;
use Illuminate\Support\Facades\Cache;

it('keeps the key lock held while a slow action still runs', function () {
    $heldAfterSlowAction = null;

    app(SendKeys::class)->once('send-keys-test', function () use (&$heldAfterSlowAction): array {
        $this->travel(100)->seconds();
        $heldAfterSlowAction = ! Cache::lock('send-keys-test:lock', 1)->get();

        return ['ok' => true];
    });

    expect($heldAfterSlowAction)->toBeTrue();
});
