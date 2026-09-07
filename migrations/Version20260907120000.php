<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_clicked_at + click_count to links (click tracking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD COLUMN last_clicked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE links ADD COLUMN click_count INTEGER NOT NULL DEFAULT 0');
        $this->addSql('CREATE INDEX IDX_links_last_clicked_at ON links (last_clicked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_links_last_clicked_at');
        $this->addSql('ALTER TABLE links DROP COLUMN click_count');
        $this->addSql('ALTER TABLE links DROP COLUMN last_clicked_at');
    }
}
