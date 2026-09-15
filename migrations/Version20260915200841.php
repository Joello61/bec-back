<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915200841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute transactions.provider_charge_id (réconciliation webhook des remboursements externes, Partie C point 5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD provider_charge_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_provider_charge_id ON transactions (provider, provider_charge_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_provider_charge_id');
        $this->addSql('ALTER TABLE transactions DROP provider_charge_id');
    }
}
