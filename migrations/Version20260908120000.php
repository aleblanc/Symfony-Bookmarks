<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add skip_processing flag to collections ("à trier"/inbox folders excluded from auto processing)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections ADD COLUMN skip_processing BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collections DROP COLUMN skip_processing');
    }
}
