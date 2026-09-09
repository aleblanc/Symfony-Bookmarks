<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies the SQLite PRAGMAs the app relies on, on every new connection:
 *
 * - `foreign_keys = ON`: SQLite disables FK enforcement by default, so the
 *   `ON DELETE CASCADE` declared on our schema would be silently ignored —
 *   deleting a Collection would leave its Links orphaned (pointing at a row that
 *   no longer exists), later blowing up as an EntityNotFoundException when the
 *   archiver loads the Link's collection.
 * - `journal_mode = WAL`: the app has concurrent writers (the cron commands) and
 *   readers (the web UI) on the same file. The default rollback journal makes a
 *   writer block readers → `SQLITE_BUSY`. WAL lets reads proceed during a write.
 *   Set per-connection (not via a one-off migration) so it is applied reliably
 *   on every open. WAL creates sidecar `-wal`/`-shm` files next to the database.
 * - `busy_timeout = 5000`: wait up to 5s for a lock instead of failing instantly,
 *   smoothing over the brief windows where WAL still needs an exclusive lock.
 */
#[AsMiddleware]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA journal_mode = WAL');
                $connection->exec('PRAGMA busy_timeout = 5000');

                return $connection;
            }
        };
    }
}
