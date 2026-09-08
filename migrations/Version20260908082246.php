<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908082246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.deleted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_users_deleted_at ON users (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        // "CREATE SCHEMA public" retire du down() genere par Doctrine : le schema existe deja,
        // cette ligne fait systematiquement echouer le rollback (SQLSTATE[42P06]).
        $this->addSql('DROP INDEX idx_users_deleted_at');
        $this->addSql('ALTER TABLE users DROP deleted_at');
    }
}
