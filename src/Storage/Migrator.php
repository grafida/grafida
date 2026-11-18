<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Joomla\Database\DatabaseInterface;
use PDO;

/**
 * Applies SQL migration files in lexicographic order, exactly once each.
 *
 * Migrations live in src/sql/NN_name.sql. Applied file names are
 * tracked in the `schema_migrations` table so re-runs are idempotent.
 */
final class Migrator
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $migrationsDir = __DIR__ . '/../sql',
    ) {}

    public function migrate(): void
    {
        $this->db->setQuery(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        )->execute();

        /** @var list<string> $appliedList */
        $appliedList = $this->db->setQuery('SELECT name FROM schema_migrations')->loadColumn();
        $applied     = array_flip($appliedList);

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);

            if (isset($applied[$name])) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            $this->db->transactionStart();

            try {
                // The migration files contain multiple statements *and* `--`
                // line comments. A prepared statement executes only the first
                // statement, and DatabaseDriver::splitSql() is a naive `;`
                // splitter that does not strip comments — either would
                // silently corrupt the schema. Hand the whole file to PDO
                // directly, exactly as the old Database-based migrator did.
                $connection = $this->db->getConnection();
                \assert($connection instanceof PDO);
                $connection->exec($sql);

                // bind() takes its value by reference and is typed `mixed`,
                // which would otherwise widen $name's type for the rest of
                // this scope (including the catch block below) — bind from
                // throwaway copies instead.
                $boundName      = $name;
                $boundAppliedAt = gmdate('Y-m-d H:i:s');

                $query = $this->db->createQuery()
                    ->setQuery('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :applied_at)')
                    ->bind(':name', $boundName)
                    ->bind(':applied_at', $boundAppliedAt);

                $this->db->setQuery($query)->execute();

                $this->db->transactionCommit();
            } catch (\Throwable $e) {
                $this->db->transactionRollback();

                throw new \RuntimeException(
                    sprintf('Migration "%s" failed: %s', $name, $e->getMessage()),
                    0,
                    $e
                );
            }
        }
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $globResult = glob(rtrim($this->migrationsDir, '/\\') . \DIRECTORY_SEPARATOR . '*.sql');
        $files      = $globResult !== false ? $globResult : [];

        sort($files, \SORT_STRING);

        return $files;
    }
}
