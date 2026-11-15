<?php

namespace App\Services\Devices;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a transaction that takes row locks so that a request waiting on another one fails fast with a 503 instead of hanging for the database default (50 seconds on MariaDB).
 */
class ShortLockWait
{
    public const LOCK_WAIT_SECONDS = 5;

    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  (Closure(): Throwable)|null  $onTimeout  builds the exception thrown when the lock wait runs out; a device flow exception by default
     * @return TReturn
     *
     * @throws DeviceFlowException
     */
    public static function transaction(Closure $callback, ?Closure $onTimeout = null): mixed
    {
        $connection = DB::connection();
        $isMysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $previous = null;

        try {
            return $connection->transaction(function () use ($callback, $connection, $isMysql, &$previous) {
                if ($isMysql) {
                    $previous = $connection->selectOne('select @@session.innodb_lock_wait_timeout as seconds')->seconds;
                    $connection->statement('set session innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
                }

                return $callback();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === self::LOCK_WAIT_TIMEOUT_ERROR) {
                throw $onTimeout !== null
                    ? $onTimeout()
                    : new DeviceFlowException('unavailable', 'Yak is busy with another request for this device. Try again in a few seconds.', 503);
            }

            throw $exception;
        } finally {
            if ($previous !== null) {
                $connection->statement('set session innodb_lock_wait_timeout = ' . (int) $previous);
            }
        }
    }
}
