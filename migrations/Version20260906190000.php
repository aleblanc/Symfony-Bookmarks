<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add preview_image column to links (downloaded og:image thumbnail)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links ADD COLUMN preview_image VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP COLUMN preview_image');
    }
}
