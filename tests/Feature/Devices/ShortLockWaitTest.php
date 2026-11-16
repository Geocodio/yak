<?php

use App\Services\Devices\DeviceFlowException;
use App\Services\Devices\ShortLockWait;
use Illuminate\Database\QueryException;

function databaseError(int $code): QueryException
{
    $driverError = new PDOException('database error');
    $driverError->errorInfo = ['HY000', $code, 'database error'];

    return new QueryException('mysql', 'select 1', [], $driverError);
}

it('turns a lock wait timeout into a 503 unavailable', function () {
    expect(fn () => ShortLockWait::transaction(fn () => throw databaseError(1205)))
        ->toThrow(fn (DeviceFlowException $exception) => expect($exception->errorCode)->toBe('unavailable')->and($exception->status)->toBe(503));
});

it('lets other database errors through and returns the result otherwise', function () {
    expect(ShortLockWait::transaction(fn () => 'done'))->toBe('done')
        ->and(fn () => ShortLockWait::transaction(fn () => throw databaseError(1062)))->toThrow(QueryException::class);
});
