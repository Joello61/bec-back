<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912183408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les prix annuels sur SubscriptionPlan et UserSubscription.billingPeriod (Lot 6.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_plans ADD price_amount_eur_yearly NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD price_amount_xaf_yearly NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plans ADD stripe_price_id_yearly VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CF5F99A272067250 ON subscription_plans (stripe_price_id_yearly)');
        // DEFAULT necessaire pour le backfill des lignes existantes (table non vide) -
        // retire juste apres, le mapping Doctrine ne declarant pas options:['default'=>...].
        $this->addSql("ALTER TABLE user_subscriptions ADD billing_period VARCHAR(20) NOT NULL DEFAULT 'monthly'");
        $this->addSql('ALTER TABLE user_subscriptions ALTER billing_period DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_subscriptions DROP billing_period');
        $this->addSql('DROP INDEX UNIQ_CF5F99A272067250');
        $this->addSql('ALTER TABLE subscription_plans DROP price_amount_eur_yearly');
        $this->addSql('ALTER TABLE subscription_plans DROP price_amount_xaf_yearly');
        $this->addSql('ALTER TABLE subscription_plans DROP stripe_price_id_yearly');
    }
}
