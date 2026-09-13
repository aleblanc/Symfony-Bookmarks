<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at to links (modification timestamp for API v2 / sync delta & conflicts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD COLUMN updated_at DATETIME DEFAULT NULL');
        // Backfill existing rows so they have a sensible modification time.
        $this->addSql('UPDATE links SET updated_at = created_at WHERE updated_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP COLUMN updated_at');
    }
}
