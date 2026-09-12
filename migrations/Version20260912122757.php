<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912122757 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute isFeatured (mise en avant page tarifs) sur subscription_plans et boost_offers (Lot 5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boost_offers ADD is_featured BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE subscription_plans ADD is_featured BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boost_offers DROP is_featured');
        $this->addSql('ALTER TABLE subscription_plans DROP is_featured');
    }
}
