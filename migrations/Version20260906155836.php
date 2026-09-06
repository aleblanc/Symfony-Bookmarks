<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260906155836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema with FTS5 index on links and seeded Perso/Pro dashboards';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE archive_assets (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(32) NOT NULL, relative_path VARCHAR(500) NOT NULL, size_bytes INTEGER NOT NULL, created_at DATETIME NOT NULL, link_id INTEGER NOT NULL, CONSTRAINT FK_84D4699BADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_84D4699BADA40271 ON archive_assets (link_id)');
        $this->addSql('CREATE TABLE collections (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(128) NOT NULL, description VARCHAR(500) DEFAULT NULL, color VARCHAR(16) NOT NULL, icon VARCHAR(4) NOT NULL, created_at DATETIME NOT NULL, dashboard_id INTEGER NOT NULL, vault_id INTEGER DEFAULT NULL, CONSTRAINT FK_D325D3EEB9D04D2B FOREIGN KEY (dashboard_id) REFERENCES dashboards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D325D3EE58AC2DF8 FOREIGN KEY (vault_id) REFERENCES vaults (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D325D3EEB9D04D2B ON collections (dashboard_id)');
        $this->addSql('CREATE INDEX IDX_D325D3EE58AC2DF8 ON collections (vault_id)');
        $this->addSql('CREATE TABLE dashboards (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, color VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A83421DA5E237E06 ON dashboards (name)');
        $this->addSql('CREATE TABLE links (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, name VARCHAR(500) DEFAULT NULL, description CLOB DEFAULT NULL, text_content CLOB DEFAULT NULL, icon_path VARCHAR(500) DEFAULT NULL, status VARCHAR(16) NOT NULL, ai_status VARCHAR(16) NOT NULL, last_error CLOB DEFAULT NULL, is_encrypted BOOLEAN NOT NULL, created_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, collection_id INTEGER NOT NULL, CONSTRAINT FK_D182A118514956FD FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D182A118514956FD ON links (collection_id)');
        $this->addSql('CREATE INDEX IDX_D182A1187B00651C ON links (status)');
        $this->addSql('CREATE INDEX IDX_D182A1187DEA6738 ON links (ai_status)');
        $this->addSql('CREATE TABLE link_tag (link_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (link_id, tag_id), CONSTRAINT FK_4FF23AB8ADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4FF23AB8BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4FF23AB8ADA40271 ON link_tag (link_id)');
        $this->addSql('CREATE INDEX IDX_4FF23AB8BAD26311 ON link_tag (tag_id)');
        $this->addSql('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, dashboard_id INTEGER NOT NULL, CONSTRAINT FK_6FBC9426B9D04D2B FOREIGN KEY (dashboard_id) REFERENCES dashboards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_6FBC9426B9D04D2B ON tags (dashboard_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6FBC9426B9D04D2B5E237E06 ON tags (dashboard_id, name)');
        $this->addSql('CREATE TABLE vaults (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, password_hash VARCHAR(255) NOT NULL, kdf_salt VARCHAR(64) NOT NULL, wrapped_key VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5798EF1C5E237E06 ON vaults (name)');

        // Full-text search on links
        $this->addSql("CREATE VIRTUAL TABLE links_fts USING fts5(name, description, text_content, url, content='links', content_rowid='id')");
        $this->addSql('CREATE TRIGGER links_ai_ins AFTER INSERT ON links BEGIN INSERT INTO links_fts(rowid, name, description, text_content, url) VALUES (new.id, new.name, new.description, new.text_content, new.url); END');
        $this->addSql("CREATE TRIGGER links_ai_del AFTER DELETE ON links BEGIN INSERT INTO links_fts(links_fts, rowid, name, description, text_content, url) VALUES('delete', old.id, old.name, old.description, old.text_content, old.url); END");
        $this->addSql("CREATE TRIGGER links_ai_upd AFTER UPDATE ON links BEGIN INSERT INTO links_fts(links_fts, rowid, name, description, text_content, url) VALUES('delete', old.id, old.name, old.description, old.text_content, old.url); INSERT INTO links_fts(rowid, name, description, text_content, url) VALUES (new.id, new.name, new.description, new.text_content, new.url); END");

        // Seed the two default dashboards
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->addSql("INSERT INTO dashboards (name, color, created_at) VALUES ('Perso', '#3b82f6', '{$now}')");
        $this->addSql("INSERT INTO dashboards (name, color, created_at) VALUES ('Pro',   '#10b981', '{$now}')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS links_ai_upd');
        $this->addSql('DROP TRIGGER IF EXISTS links_ai_del');
        $this->addSql('DROP TRIGGER IF EXISTS links_ai_ins');
        $this->addSql('DROP TABLE IF EXISTS links_fts');
        $this->addSql('DROP TABLE archive_assets');
        $this->addSql('DROP TABLE collections');
        $this->addSql('DROP TABLE dashboards');
        $this->addSql('DROP TABLE links');
        $this->addSql('DROP TABLE link_tag');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE vaults');
    }
}
