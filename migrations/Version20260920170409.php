<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920170409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_server (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              name VARCHAR(64) NOT NULL,
              url VARCHAR(512) NOT NULL,
              protocol VARCHAR(16) NOT NULL,
              enabled BOOLEAN NOT NULL,
              cred_var VARCHAR(128) DEFAULT NULL,
              last_synced_at DATETIME DEFAULT NULL,
              last_sync_status VARCHAR(255) DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_server_name ON mcp_server (name)');
        $this->addSql(<<<'SQL'
            CREATE TABLE tool (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              name VARCHAR(128) NOT NULL,
              description CLOB DEFAULT NULL,
              tags CLOB NOT NULL,
              schema CLOB NOT NULL,
              side_effect BOOLEAN NOT NULL,
              pinned BOOLEAN NOT NULL,
              discovered_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              server_id INTEGER DEFAULT NULL,
              CONSTRAINT FK_20F33ED11844E6B7 FOREIGN KEY (server_id) REFERENCES mcp_server (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_server_name ON tool (server_id, name)');
        $this->addSql('CREATE INDEX IDX_20F33ED11844E6B7 ON tool (server_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE mcp_server');
        $this->addSql('DROP TABLE tool');
    }
}
