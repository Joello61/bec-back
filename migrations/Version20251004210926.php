<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251004210926 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE password_reset_tokens (id SERIAL NOT NULL, user_id INT NOT NULL, token VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3967A2165F37A13B ON password_reset_tokens (token)');
        $this->addSql('CREATE INDEX IDX_3967A216A76ED395 ON password_reset_tokens (user_id)');
        $this->addSql('CREATE INDEX idx_reset_token ON password_reset_tokens (token)');
        $this->addSql('CREATE TABLE verification_codes (id SERIAL NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, code VARCHAR(10) NOT NULL, type VARCHAR(20) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_verification_email ON verification_codes (email)');
        $this->addSql('CREATE INDEX idx_verification_phone ON verification_codes (phone)');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE users ADD auth_provider VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD facebook_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ALTER password DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE password_reset_tokens DROP CONSTRAINT FK_3967A216A76ED395');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE verification_codes');
        $this->addSql('ALTER TABLE users DROP auth_provider');
        $this->addSql('ALTER TABLE users DROP google_id');
        $this->addSql('ALTER TABLE users DROP facebook_id');
        $this->addSql('ALTER TABLE users ALTER password SET NOT NULL');
    }
}
