<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912042913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le boost de visibilite (Lot 2) : catalogue BoostOffer, achats Boost, FK transactions.boost_id';
    }

    public function up(Schema $schema): void
    {
        // Drift messenger_messages non lie a cette migration (derive preexistante deja
        // documentee, cf. Lot 1) retire du diff auto-genere - hors perimetre de ce lot.
        $this->addSql('CREATE TABLE boost_offers (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, duration_days INT NOT NULL, price_amount_eur NUMERIC(10, 2) NOT NULL, is_active BOOLEAN NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE boosts (id SERIAL NOT NULL, user_id INT NOT NULL, voyage_id INT DEFAULT NULL, demande_id INT DEFAULT NULL, offer_id INT NOT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, withdrawal_waiver_consented_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_58314246A76ED395 ON boosts (user_id)');
        $this->addSql('CREATE INDEX IDX_5831424668C9E5AF ON boosts (voyage_id)');
        $this->addSql('CREATE INDEX IDX_5831424680E95E18 ON boosts (demande_id)');
        $this->addSql('CREATE INDEX IDX_5831424653C674EE ON boosts (offer_id)');
        $this->addSql('ALTER TABLE boosts ADD CONSTRAINT FK_58314246A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE boosts ADD CONSTRAINT FK_5831424668C9E5AF FOREIGN KEY (voyage_id) REFERENCES voyages (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE boosts ADD CONSTRAINT FK_5831424680E95E18 FOREIGN KEY (demande_id) REFERENCES demandes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE boosts ADD CONSTRAINT FK_5831424653C674EE FOREIGN KEY (offer_id) REFERENCES boost_offers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD boost_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CB09F48DA FOREIGN KEY (boost_id) REFERENCES boosts (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_EAA81A4CB09F48DA ON transactions (boost_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4CB09F48DA');
        $this->addSql('ALTER TABLE boosts DROP CONSTRAINT FK_58314246A76ED395');
        $this->addSql('ALTER TABLE boosts DROP CONSTRAINT FK_5831424668C9E5AF');
        $this->addSql('ALTER TABLE boosts DROP CONSTRAINT FK_5831424680E95E18');
        $this->addSql('ALTER TABLE boosts DROP CONSTRAINT FK_5831424653C674EE');
        $this->addSql('DROP TABLE boost_offers');
        $this->addSql('DROP TABLE boosts');
        $this->addSql('DROP INDEX IDX_EAA81A4CB09F48DA');
        $this->addSql('ALTER TABLE transactions DROP boost_id');
    }
}
