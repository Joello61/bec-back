<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912054400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes Mobile Money (Lot 3) : priceAmountXaf sur SubscriptionPlan/BoostOffer, renewalReminderSentAt sur UserSubscription';
    }

    public function up(Schema $schema): void
    {
        // Drift messenger_messages non lie a cette migration (derive preexistante deja
        // documentee, cf. Lot 1/2) retire du diff auto-genere - hors perimetre de ce lot.
        $this->addSql('ALTER TABLE boost_offers ADD price_amount_xaf NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD price_amount_xaf NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_subscriptions ADD renewal_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE boost_offers DROP price_amount_xaf');
        $this->addSql('ALTER TABLE user_subscriptions DROP renewal_reminder_sent_at');
        $this->addSql('ALTER TABLE subscription_plans DROP price_amount_xaf');
    }
}
