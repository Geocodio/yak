<?php

it('gives a stable token to prefix Redis keys with', function () {
    expect(parallelToken())->toBeString()->not->toBe('')
        ->and(parallelToken())->toBe(parallelToken());
});
