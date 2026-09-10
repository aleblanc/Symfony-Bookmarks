<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add favorite flag to links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD COLUMN favorite BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP COLUMN favorite');
    }
}
