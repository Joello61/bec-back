<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251012164756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE currencies (id SERIAL NOT NULL, code VARCHAR(3) NOT NULL, name VARCHAR(100) NOT NULL, symbol VARCHAR(10) NOT NULL, decimals SMALLINT NOT NULL, exchange_rate NUMERIC(12, 6) NOT NULL, rate_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN NOT NULL, countries JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_37C4469377153098 ON currencies (code)');
        $this->addSql('ALTER TABLE demandes ADD currency VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE propositions ADD currency VARCHAR(3) NOT NULL');
        $this->addSql('ALTER TABLE voyages ADD currency VARCHAR(3) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP TABLE currencies');
        $this->addSql('ALTER TABLE propositions DROP currency');
        $this->addSql('ALTER TABLE voyages DROP currency');
        $this->addSql('ALTER TABLE demandes DROP currency');
    }
}
