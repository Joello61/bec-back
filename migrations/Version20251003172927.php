<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251003172927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE avis (id SERIAL NOT NULL, auteur_id INT NOT NULL, cible_id INT NOT NULL, voyage_id INT DEFAULT NULL, note SMALLINT NOT NULL, commentaire TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8F91ABF060BB6FE6 ON avis (auteur_id)');
        $this->addSql('CREATE INDEX IDX_8F91ABF0A96E5E09 ON avis (cible_id)');
        $this->addSql('CREATE INDEX IDX_8F91ABF068C9E5AF ON avis (voyage_id)');
        $this->addSql('CREATE TABLE demandes (id SERIAL NOT NULL, client_id INT NOT NULL, ville_depart VARCHAR(255) NOT NULL, ville_arrivee VARCHAR(255) NOT NULL, date_limite DATE DEFAULT NULL, poids_estime NUMERIC(5, 2) NOT NULL, description TEXT NOT NULL, statut VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BD940CBB19EB6921 ON demandes (client_id)');
        $this->addSql('CREATE TABLE favoris (id SERIAL NOT NULL, user_id INT NOT NULL, voyage_id INT DEFAULT NULL, demande_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8933C432A76ED395 ON favoris (user_id)');
        $this->addSql('CREATE INDEX IDX_8933C43268C9E5AF ON favoris (voyage_id)');
        $this->addSql('CREATE INDEX IDX_8933C43280E95E18 ON favoris (demande_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_user_voyage ON favoris (user_id, voyage_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_user_demande ON favoris (user_id, demande_id)');
        $this->addSql('CREATE TABLE messages (id SERIAL NOT NULL, expediteur_id INT NOT NULL, destinataire_id INT NOT NULL, contenu TEXT NOT NULL, lu BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DB021E9610335F61 ON messages (expediteur_id)');
        $this->addSql('CREATE INDEX IDX_DB021E96A4F84F6E ON messages (destinataire_id)');
        $this->addSql('CREATE TABLE notifications (id SERIAL NOT NULL, user_id INT NOT NULL, type VARCHAR(50) NOT NULL, titre VARCHAR(255) NOT NULL, message TEXT NOT NULL, data JSON DEFAULT NULL, lue BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED395 ON notifications (user_id)');
        $this->addSql('CREATE TABLE signalements (id SERIAL NOT NULL, signaleur_id INT NOT NULL, voyage_id INT DEFAULT NULL, demande_id INT DEFAULT NULL, motif VARCHAR(50) NOT NULL, description TEXT NOT NULL, statut VARCHAR(50) NOT NULL, reponse_admin TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_120AE27C5687B3E ON signalements (signaleur_id)');
        $this->addSql('CREATE INDEX IDX_120AE2768C9E5AF ON signalements (voyage_id)');
        $this->addSql('CREATE INDEX IDX_120AE2780E95E18 ON signalements (demande_id)');
        $this->addSql('CREATE TABLE users (id SERIAL NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, bio TEXT DEFAULT NULL, email_verifie BOOLEAN NOT NULL, telephone_verifie BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE TABLE voyages (id SERIAL NOT NULL, voyageur_id INT NOT NULL, ville_depart VARCHAR(255) NOT NULL, ville_arrivee VARCHAR(255) NOT NULL, date_depart DATE NOT NULL, date_arrivee DATE NOT NULL, poids_disponible NUMERIC(5, 2) NOT NULL, description TEXT DEFAULT NULL, statut VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_30F7F962915402 ON voyages (voyageur_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify(\'messenger_messages\', NEW.queue_name::text);
                RETURN NEW;
            END;
        $$ LANGUAGE plpgsql;');
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages;');
        $this->addSql('CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF060BB6FE6 FOREIGN KEY (auteur_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF0A96E5E09 FOREIGN KEY (cible_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF068C9E5AF FOREIGN KEY (voyage_id) REFERENCES voyages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demandes ADD CONSTRAINT FK_BD940CBB19EB6921 FOREIGN KEY (client_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE favoris ADD CONSTRAINT FK_8933C432A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE favoris ADD CONSTRAINT FK_8933C43268C9E5AF FOREIGN KEY (voyage_id) REFERENCES voyages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE favoris ADD CONSTRAINT FK_8933C43280E95E18 FOREIGN KEY (demande_id) REFERENCES demandes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E9610335F61 FOREIGN KEY (expediteur_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E96A4F84F6E FOREIGN KEY (destinataire_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE signalements ADD CONSTRAINT FK_120AE27C5687B3E FOREIGN KEY (signaleur_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE signalements ADD CONSTRAINT FK_120AE2768C9E5AF FOREIGN KEY (voyage_id) REFERENCES voyages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE signalements ADD CONSTRAINT FK_120AE2780E95E18 FOREIGN KEY (demande_id) REFERENCES demandes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE voyages ADD CONSTRAINT FK_30F7F962915402 FOREIGN KEY (voyageur_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE avis DROP CONSTRAINT FK_8F91ABF060BB6FE6');
        $this->addSql('ALTER TABLE avis DROP CONSTRAINT FK_8F91ABF0A96E5E09');
        $this->addSql('ALTER TABLE avis DROP CONSTRAINT FK_8F91ABF068C9E5AF');
        $this->addSql('ALTER TABLE demandes DROP CONSTRAINT FK_BD940CBB19EB6921');
        $this->addSql('ALTER TABLE favoris DROP CONSTRAINT FK_8933C432A76ED395');
        $this->addSql('ALTER TABLE favoris DROP CONSTRAINT FK_8933C43268C9E5AF');
        $this->addSql('ALTER TABLE favoris DROP CONSTRAINT FK_8933C43280E95E18');
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT FK_DB021E9610335F61');
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT FK_DB021E96A4F84F6E');
        $this->addSql('ALTER TABLE notifications DROP CONSTRAINT FK_6000B0D3A76ED395');
        $this->addSql('ALTER TABLE signalements DROP CONSTRAINT FK_120AE27C5687B3E');
        $this->addSql('ALTER TABLE signalements DROP CONSTRAINT FK_120AE2768C9E5AF');
        $this->addSql('ALTER TABLE signalements DROP CONSTRAINT FK_120AE2780E95E18');
        $this->addSql('ALTER TABLE voyages DROP CONSTRAINT FK_30F7F962915402');
        $this->addSql('DROP TABLE avis');
        $this->addSql('DROP TABLE demandes');
        $this->addSql('DROP TABLE favoris');
        $this->addSql('DROP TABLE messages');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE signalements');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE voyages');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
