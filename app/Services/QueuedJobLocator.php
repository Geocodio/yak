<?php

namespace App\Services;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Answers "is a job with this uuid still tracked by the queue?" on the
 * default queue connection, whatever its driver. A job counts while it
 * is pending, delayed, or reserved by a worker.
 *
 * The uuid lives inside the serialized payload, so both drivers search
 * payload text. Yak's queues hold a handful of jobs, which keeps a full
 * scan cheap.
 */
class QueuedJobLocator
{
    public function contains(string $queue, string $uuid): bool
    {
        $connection = Queue::connection();
        $needle = '"uuid":"' . $uuid . '"';

        if ($connection instanceof DatabaseQueue) {
            return $connection->getDatabase()
                ->table((string) config('queue.connections.' . $connection->getConnectionName() . '.table', 'jobs'))
                ->where('queue', $queue)
                ->where('payload', 'like', '%' . $needle . '%')
                ->exists();
        }

        if ($connection instanceof RedisQueue) {
            $redis = $connection->getConnection();
            $key = 'queues:' . $queue;

            $payloads = [
                ...$redis->lrange($key, 0, -1),
                ...$redis->zrange($key . ':delayed', 0, -1),
                ...$redis->zrange($key . ':reserved', 0, -1),
            ];

            foreach ($payloads as $payload) {
                if (str_contains((string) $payload, $needle)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
