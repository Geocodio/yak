<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Makes a locked select of one row run out of lock wait while `$busy` is true, the way MariaDB
 * answers error 1205. It fires only for that row, only inside a lock transaction.
 */
final class BusyRow
{
    public bool $busy = true;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function on(string $modelClass, Model $row): self
    {
        $state = new self;

        $modelClass::retrieved(function (Model $model) use ($row, $state): void {
            if ($state->busy && $model->is($row) && DB::transactionLevel() > 1) {
                $exception = new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded'));
                $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];

                throw $exception;
            }
        });

        return $state;
    }
}
