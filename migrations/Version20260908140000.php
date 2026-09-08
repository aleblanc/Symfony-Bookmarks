<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add link health check columns (health_status, http_status, health_checked_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE links ADD COLUMN health_status VARCHAR(16) NOT NULL DEFAULT 'unknown'");
        $this->addSql('ALTER TABLE links ADD COLUMN http_status INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE links ADD COLUMN health_checked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_links_health_status ON links (health_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_links_health_status');
        $this->addSql('ALTER TABLE links DROP COLUMN health_checked_at');
        $this->addSql('ALTER TABLE links DROP COLUMN http_status');
        $this->addSql('ALTER TABLE links DROP COLUMN health_status');
    }
}
