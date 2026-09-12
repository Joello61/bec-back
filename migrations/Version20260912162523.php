<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912162523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute Transaction.refundedAt (Lot 6.1 - remboursements admin)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD refunded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP refunded_at');
    }
}
