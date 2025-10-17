<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251017103258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cities (id SERIAL NOT NULL, country_id INT NOT NULL, geoname_id INT DEFAULT NULL, name VARCHAR(200) NOT NULL, alternate_name VARCHAR(200) DEFAULT NULL, admin1_code VARCHAR(20) DEFAULT NULL, admin1_name VARCHAR(100) DEFAULT NULL, latitude NUMERIC(10, 7) DEFAULT NULL, longitude NUMERIC(10, 7) DEFAULT NULL, population INT DEFAULT NULL, timezone VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_city_name ON cities (name)');
        $this->addSql('CREATE INDEX idx_city_country ON cities (country_id)');
        $this->addSql('CREATE INDEX idx_city_search ON cities (country_id, name)');
        $this->addSql('CREATE TABLE countries (id SERIAL NOT NULL, code VARCHAR(2) NOT NULL, name VARCHAR(100) NOT NULL, name_fr VARCHAR(100) DEFAULT NULL, iso3 VARCHAR(3) DEFAULT NULL, continent VARCHAR(2) DEFAULT NULL, capital VARCHAR(100) DEFAULT NULL, languages VARCHAR(200) DEFAULT NULL, currency_code VARCHAR(3) DEFAULT NULL, phone_code VARCHAR(20) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5D66EBAD77153098 ON countries (code)');
        $this->addSql('CREATE INDEX idx_country_code ON countries (code)');
        $this->addSql('CREATE INDEX idx_country_name ON countries (name)');
        $this->addSql('ALTER TABLE cities ADD CONSTRAINT FK_D95DB16BF92F3E70 FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE cities DROP CONSTRAINT FK_D95DB16BF92F3E70');
        $this->addSql('DROP TABLE cities');
        $this->addSql('DROP TABLE countries');
    }
}
