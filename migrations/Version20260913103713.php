<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260913103713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la préférence notify_on_quota_warning sur user_settings (Lot N6 monétisation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_settings ADD notify_on_quota_warning BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE user_settings ALTER notify_on_quota_warning DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_settings DROP notify_on_quota_warning');
    }
}
