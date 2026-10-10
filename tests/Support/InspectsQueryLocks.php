<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Permite verificar en tests (SQLite) que el código pide lockForUpdate() y en
 * qué orden respecto a otras consultas.
 */
trait InspectsQueryLocks
{
    /**
     * Activa el query log con un grammar que deja visible lockForUpdate() en
     * el SQL (ver LockVisibleSQLiteGrammar).
     */
    private function logQueriesWithVisibleLocks(): void
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new LockVisibleSQLiteGrammar($connection));
        $connection->enableQueryLog();
    }

    /**
     * Posición en el query log de la primera consulta que cumple ambos
     * fragmentos, o null si no hay ninguna.
     */
    private function queryIndex(string $fragment, ?string $alsoContaining = null): ?int
    {
        foreach (DB::getQueryLog() as $index => $entry) {
            if (str_contains($entry['query'], $fragment) && ($alsoContaining === null || str_contains($entry['query'], $alsoContaining))) {
                return $index;
            }
        }

        return null;
    }

    private function lockedReadIndex(string $table): ?int
    {
        return $this->queryIndex("from \"{$table}\"", LockVisibleSQLiteGrammar::MARKER);
    }

    private function assertLockedBeforeUpdate(string $table): void
    {
        $lockIndex = $this->lockedReadIndex($table);
        $updateIndex = $this->queryIndex("update \"{$table}\"");

        $this->assertNotNull($lockIndex, "No se leyó {$table} con lockForUpdate().");
        $this->assertNotNull($updateIndex, "No se actualizó {$table}.");
        $this->assertLessThan($updateIndex, $lockIndex, "El lock sobre {$table} debe tomarse antes de mutarla.");
    }
}
