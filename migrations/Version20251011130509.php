<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251011130509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD pays VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD ville VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD quartier VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD adresse_ligne1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD adresse_ligne2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD code_postal VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE users DROP pays');
        $this->addSql('ALTER TABLE users DROP ville');
        $this->addSql('ALTER TABLE users DROP quartier');
        $this->addSql('ALTER TABLE users DROP adresse_ligne1');
        $this->addSql('ALTER TABLE users DROP adresse_ligne2');
        $this->addSql('ALTER TABLE users DROP code_postal');
    }
}
