<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * SQLite disables foreign-key enforcement by default, so the `ON DELETE CASCADE`
 * declared on our schema is silently ignored: deleting a Collection leaves its
 * Links orphaned (pointing at a row that no longer exists), which later blows up
 * as an EntityNotFoundException when the archiver loads the Link's collection.
 *
 * This middleware issues `PRAGMA foreign_keys = ON` on every new connection so
 * the database enforces the cascade the schema already asks for.
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

                return $connection;
            }
        };
    }
}
