<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911234235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le socle monétisation (Lot 1) : catalogue SubscriptionPlan, abonnements UserSubscription, transactions de paiement';
    }

    public function up(Schema $schema): void
    {
        // Drift messenger_messages non lié à cette migration (dérive préexistante déjà
        // documentée) retiré du diff auto-généré - hors périmètre de ce lot.
        $this->addSql('CREATE TABLE subscription_plans (id SERIAL NOT NULL, code VARCHAR(30) NOT NULL, name VARCHAR(100) NOT NULL, price_amount_eur NUMERIC(10, 2) DEFAULT NULL, billing_period VARCHAR(20) NOT NULL, max_active_voyages INT DEFAULT NULL, max_active_demandes INT DEFAULT NULL, has_badge BOOLEAN NOT NULL, stripe_price_id VARCHAR(255) DEFAULT NULL, is_active BOOLEAN NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CF5F99A277153098 ON subscription_plans (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CF5F99A28B531BD4 ON subscription_plans (stripe_price_id)');
        $this->addSql('CREATE TABLE transactions (id SERIAL NOT NULL, user_id INT NOT NULL, subscription_id INT DEFAULT NULL, type VARCHAR(30) NOT NULL, provider VARCHAR(20) NOT NULL, provider_payment_id VARCHAR(255) NOT NULL, payment_method_family VARCHAR(20) NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, raw_payload JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EAA81A4CA76ED395 ON transactions (user_id)');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9A1887DC ON transactions (subscription_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_payment_id ON transactions (provider, provider_payment_id)');
        $this->addSql('CREATE TABLE user_subscriptions (id SERIAL NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(20) NOT NULL, provider_customer_id VARCHAR(255) DEFAULT NULL, provider_subscription_id VARCHAR(255) DEFAULT NULL, current_period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancel_at_period_end BOOLEAN NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, withdrawal_waiver_consented_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EAF92751A76ED395 ON user_subscriptions (user_id)');
        $this->addSql('CREATE INDEX IDX_EAF92751E899029B ON user_subscriptions (plan_id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C9A1887DC FOREIGN KEY (subscription_id) REFERENCES user_subscriptions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_subscriptions ADD CONSTRAINT FK_EAF92751A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_subscriptions ADD CONSTRAINT FK_EAF92751E899029B FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4CA76ED395');
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C9A1887DC');
        $this->addSql('ALTER TABLE user_subscriptions DROP CONSTRAINT FK_EAF92751A76ED395');
        $this->addSql('ALTER TABLE user_subscriptions DROP CONSTRAINT FK_EAF92751E899029B');
        $this->addSql('DROP TABLE subscription_plans');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE user_subscriptions');
    }
}
