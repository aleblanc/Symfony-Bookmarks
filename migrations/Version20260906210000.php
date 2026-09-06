<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_summary + summary_status to links (AI summarization)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE links ADD COLUMN summary_status VARCHAR(16) NOT NULL DEFAULT 'pending'");
        $this->addSql('ALTER TABLE links ADD COLUMN ai_summary CLOB DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_links_summary_status ON links (summary_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_links_summary_status');
        $this->addSql('ALTER TABLE links DROP COLUMN ai_summary');
        $this->addSql('ALTER TABLE links DROP COLUMN summary_status');
    }
}
