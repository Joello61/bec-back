<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912174642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Voyage/Demande.nombreVues et SubscriptionPlan.hasViewStats (Lot 6.2 - compteur de vues)';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT necessaire pour le backfill des lignes existantes (tables non vides) -
        // retire juste apres, le mapping Doctrine ne declarant pas options:['default'=>...].
        $this->addSql('ALTER TABLE demandes ADD nombre_vues INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE demandes ALTER nombre_vues DROP DEFAULT');
        $this->addSql('ALTER TABLE subscription_plans ADD has_view_stats BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE subscription_plans ALTER has_view_stats DROP DEFAULT');
        $this->addSql('ALTER TABLE voyages ADD nombre_vues INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE voyages ALTER nombre_vues DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demandes DROP nombre_vues');
        $this->addSql('ALTER TABLE subscription_plans DROP has_view_stats');
        $this->addSql('ALTER TABLE voyages DROP nombre_vues');
    }
}
