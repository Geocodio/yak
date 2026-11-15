<?php

namespace App\Services\Threads;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs an action once per key and replays its stored result, so a client that
 * lost the answer can repeat a request without repeating its effect.
 */
class SendKeys
{
    // Runner commands have no time limit, so the lock can in principle expire mid-action; the sender checks the database for a recorded send key before it acts.
    private const LOCK_SECONDS = 180;

    private const RESULT_MINUTES = 10;

    public function __construct(private readonly int $lockWaitSeconds = 5) {}

    /**
     * @param  Closure(): array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function once(string $key, Closure $action): array
    {
        try {
            return Cache::lock("{$key}:lock", self::LOCK_SECONDS)->block($this->lockWaitSeconds, function () use ($key, $action): array {
                $stored = Cache::get($key);

                if (is_array($stored)) {
                    return $stored;
                }

                $result = $action();
                Cache::put($key, $result, now()->addMinutes(self::RESULT_MINUTES));

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new HttpException(503, 'Yak is still handling this request. Try again in a moment.');
        }
    }
}
