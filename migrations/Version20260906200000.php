<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_id to collections for nested folders (sub-collections)';
    }

    public function up(Schema $schema): void
    {
        // SQLite cannot add a FK via ALTER; the parent_id column is a plain
        // nullable reference and cascade deletion is handled in the application
        // (CollectionController deletes descendants recursively).
        $this->addSql('ALTER TABLE collections ADD COLUMN parent_id INTEGER DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_collections_parent ON collections (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_collections_parent');
        $this->addSql('ALTER TABLE collections DROP COLUMN parent_id');
    }
}
