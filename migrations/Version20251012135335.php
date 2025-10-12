<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251012135335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE addresses (id SERIAL NOT NULL, user_id INT NOT NULL, pays VARCHAR(100) NOT NULL, ville VARCHAR(100) NOT NULL, quartier VARCHAR(100) DEFAULT NULL, adresse_ligne1 VARCHAR(255) DEFAULT NULL, adresse_ligne2 VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6FCA7516A76ED395 ON addresses (user_id)');
        $this->addSql('ALTER TABLE addresses ADD CONSTRAINT FK_6FCA7516A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE users DROP pays');
        $this->addSql('ALTER TABLE users DROP ville');
        $this->addSql('ALTER TABLE users DROP quartier');
        $this->addSql('ALTER TABLE users DROP adresse_ligne1');
        $this->addSql('ALTER TABLE users DROP adresse_ligne2');
        $this->addSql('ALTER TABLE users DROP code_postal');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE addresses DROP CONSTRAINT FK_6FCA7516A76ED395');
        $this->addSql('DROP TABLE addresses');
        $this->addSql('ALTER TABLE users ADD pays VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD ville VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD quartier VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD adresse_ligne1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD adresse_ligne2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD code_postal VARCHAR(20) DEFAULT NULL');
    }
}
