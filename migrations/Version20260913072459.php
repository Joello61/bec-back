<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260913072459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot N4 (monetisation) : invoice_number/invoice_storage_path sur transactions';
    }

    public function up(Schema $schema): void
    {
        // Drift preexistant sur messenger_messages (index), sans rapport avec ce lot -
        // volontairement exclu, cf. CLAUDE.md et plan-complements-monetisation-cobage.md.
        $this->addSql('ALTER TABLE transactions ADD invoice_number VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE transactions ADD invoice_storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAA81A4C2DA68207 ON transactions (invoice_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_EAA81A4C2DA68207');
        $this->addSql('ALTER TABLE transactions DROP invoice_number');
        $this->addSql('ALTER TABLE transactions DROP invoice_storage_path');
    }
}
