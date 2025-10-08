<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251008002049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE signalements ADD message_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE signalements ADD utilisateur_signale_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE signalements ADD CONSTRAINT FK_120AE27537A1329 FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE signalements ADD CONSTRAINT FK_120AE2737B960BE FOREIGN KEY (utilisateur_signale_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_120AE27537A1329 ON signalements (message_id)');
        $this->addSql('CREATE INDEX IDX_120AE2737B960BE ON signalements (utilisateur_signale_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE signalements DROP CONSTRAINT FK_120AE27537A1329');
        $this->addSql('ALTER TABLE signalements DROP CONSTRAINT FK_120AE2737B960BE');
        $this->addSql('DROP INDEX IDX_120AE27537A1329');
        $this->addSql('DROP INDEX IDX_120AE2737B960BE');
        $this->addSql('ALTER TABLE signalements DROP message_id');
        $this->addSql('ALTER TABLE signalements DROP utilisateur_signale_id');
    }
}
