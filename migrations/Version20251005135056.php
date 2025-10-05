<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251005135056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE propositions (id SERIAL NOT NULL, voyage_id INT NOT NULL, demande_id INT NOT NULL, client_id INT NOT NULL, voyageur_id INT NOT NULL, prix_par_kilo NUMERIC(10, 2) NOT NULL, commission_proposee_pour_un_bagage NUMERIC(10, 2) NOT NULL, message TEXT DEFAULT NULL, statut VARCHAR(50) NOT NULL, message_refus TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repondu_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E9AB028668C9E5AF ON propositions (voyage_id)');
        $this->addSql('CREATE INDEX IDX_E9AB028680E95E18 ON propositions (demande_id)');
        $this->addSql('CREATE INDEX IDX_E9AB028619EB6921 ON propositions (client_id)');
        $this->addSql('CREATE INDEX IDX_E9AB028662915402 ON propositions (voyageur_id)');
        $this->addSql('CREATE TABLE user_settings (id SERIAL NOT NULL, user_id INT NOT NULL, email_notifications_enabled BOOLEAN NOT NULL, sms_notifications_enabled BOOLEAN NOT NULL, push_notifications_enabled BOOLEAN NOT NULL, notify_on_new_message BOOLEAN NOT NULL, notify_on_matching_voyage BOOLEAN NOT NULL, notify_on_matching_demande BOOLEAN NOT NULL, notify_on_new_avis BOOLEAN NOT NULL, notify_on_favori_update BOOLEAN NOT NULL, profile_visibility VARCHAR(20) NOT NULL, show_phone BOOLEAN NOT NULL, show_email BOOLEAN NOT NULL, show_stats BOOLEAN NOT NULL, message_permission VARCHAR(20) NOT NULL, show_in_search_results BOOLEAN NOT NULL, show_last_seen BOOLEAN NOT NULL, langue VARCHAR(5) NOT NULL, devise VARCHAR(3) NOT NULL, timezone VARCHAR(50) NOT NULL, date_format VARCHAR(10) NOT NULL, cookies_consent BOOLEAN NOT NULL, analytics_consent BOOLEAN NOT NULL, marketing_consent BOOLEAN NOT NULL, data_share_consent BOOLEAN NOT NULL, consent_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, two_factor_enabled BOOLEAN NOT NULL, login_notifications BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C844C5A76ED395 ON user_settings (user_id)');
        $this->addSql('ALTER TABLE propositions ADD CONSTRAINT FK_E9AB028668C9E5AF FOREIGN KEY (voyage_id) REFERENCES voyages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE propositions ADD CONSTRAINT FK_E9AB028680E95E18 FOREIGN KEY (demande_id) REFERENCES demandes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE propositions ADD CONSTRAINT FK_E9AB028619EB6921 FOREIGN KEY (client_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE propositions ADD CONSTRAINT FK_E9AB028662915402 FOREIGN KEY (voyageur_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_settings ADD CONSTRAINT FK_5C844C5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demandes ADD prix_par_kilo NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE demandes ADD commission_proposee_pour_un_bagage NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE voyages ADD prix_par_kilo NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE voyages ADD commission_proposee_pour_un_bagage NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE propositions DROP CONSTRAINT FK_E9AB028668C9E5AF');
        $this->addSql('ALTER TABLE propositions DROP CONSTRAINT FK_E9AB028680E95E18');
        $this->addSql('ALTER TABLE propositions DROP CONSTRAINT FK_E9AB028619EB6921');
        $this->addSql('ALTER TABLE propositions DROP CONSTRAINT FK_E9AB028662915402');
        $this->addSql('ALTER TABLE user_settings DROP CONSTRAINT FK_5C844C5A76ED395');
        $this->addSql('DROP TABLE propositions');
        $this->addSql('DROP TABLE user_settings');
        $this->addSql('ALTER TABLE demandes DROP prix_par_kilo');
        $this->addSql('ALTER TABLE demandes DROP commission_proposee_pour_un_bagage');
        $this->addSql('ALTER TABLE voyages DROP prix_par_kilo');
        $this->addSql('ALTER TABLE voyages DROP commission_proposee_pour_un_bagage');
    }
}
