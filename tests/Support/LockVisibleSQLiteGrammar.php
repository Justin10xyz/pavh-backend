<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * SQLite no soporta FOR UPDATE y su grammar descarta el lock en silencio, así
 * que el query log no permite saber si el código pidió lockForUpdate(). Este
 * grammar lo deja visible como comentario SQL (SQLite lo ignora al ejecutar).
 */
class LockVisibleSQLiteGrammar extends SQLiteGrammar
{
    public const MARKER = '/* lock for update */';

    protected function compileLock(Builder $query, $value)
    {
        return $value === true ? self::MARKER : '';
    }
}
