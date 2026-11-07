<?php

it('keeps the redis retry_after above the longest job timeout', function () {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan(7200);
});
