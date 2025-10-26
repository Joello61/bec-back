<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251026002745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX refresh_token_idx');
        $this->addSql('DROP INDEX uniq_9bace7e1c74f2195');
        $this->addSql('ALTER TABLE refresh_tokens ADD validator_hash VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE refresh_tokens RENAME COLUMN refresh_token TO selector');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BACE7E19692E25D ON refresh_tokens (selector)');
        $this->addSql('CREATE INDEX selector_idx ON refresh_tokens (selector)');
        $this->addSql('CREATE INDEX expires_at_idx ON refresh_tokens (expires_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP INDEX UNIQ_9BACE7E19692E25D');
        $this->addSql('DROP INDEX selector_idx');
        $this->addSql('DROP INDEX expires_at_idx');
        $this->addSql('ALTER TABLE refresh_tokens ADD refresh_token VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE refresh_tokens DROP selector');
        $this->addSql('ALTER TABLE refresh_tokens DROP validator_hash');
        $this->addSql('CREATE INDEX refresh_token_idx ON refresh_tokens (refresh_token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_9bace7e1c74f2195 ON refresh_tokens (refresh_token)');
    }
}
