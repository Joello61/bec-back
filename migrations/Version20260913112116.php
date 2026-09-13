<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260913112116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute boosts.boost_ending_reminder_sent_at et user_settings.notify_on_boost_ending_soon (Lot N7 monétisation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boosts ADD boost_ending_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_settings ADD notify_on_boost_ending_soon BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE user_settings ALTER notify_on_boost_ending_soon DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boosts DROP boost_ending_reminder_sent_at');
        $this->addSql('ALTER TABLE user_settings DROP notify_on_boost_ending_soon');
    }
}
