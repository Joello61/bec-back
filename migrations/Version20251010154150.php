<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251010154150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_logs (id SERIAL NOT NULL, admin_id INT NOT NULL, action VARCHAR(100) NOT NULL, target_type VARCHAR(50) NOT NULL, target_id INT NOT NULL, details JSON DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D09644BC642B8210 ON admin_logs (admin_id)');
        $this->addSql('CREATE INDEX idx_action ON admin_logs (action)');
        $this->addSql('CREATE INDEX idx_target_type ON admin_logs (target_type)');
        $this->addSql('CREATE INDEX idx_created_at ON admin_logs (created_at)');
        $this->addSql('ALTER TABLE admin_logs ADD CONSTRAINT FK_D09644BC642B8210 FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE users ADD banned_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD is_banned BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD banned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD ban_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9386B8E7 FOREIGN KEY (banned_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_1483A5E9386B8E7 ON users (banned_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE admin_logs DROP CONSTRAINT FK_D09644BC642B8210');
        $this->addSql('DROP TABLE admin_logs');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9386B8E7');
        $this->addSql('DROP INDEX IDX_1483A5E9386B8E7');
        $this->addSql('ALTER TABLE users DROP banned_by_id');
        $this->addSql('ALTER TABLE users DROP is_banned');
        $this->addSql('ALTER TABLE users DROP banned_at');
        $this->addSql('ALTER TABLE users DROP ban_reason');
    }
}
