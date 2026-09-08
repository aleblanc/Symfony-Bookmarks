<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position column to collections for manual folder ordering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP COLUMN position');
    }
}
