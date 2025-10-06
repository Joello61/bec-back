<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251006003848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversations (id SERIAL NOT NULL, participant1_id INT NOT NULL, participant2_id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C2521BF1B29A9963 ON conversations (participant1_id)');
        $this->addSql('CREATE INDEX IDX_C2521BF1A02F368D ON conversations (participant2_id)');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF1B29A9963 FOREIGN KEY (participant1_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF1A02F368D FOREIGN KEY (participant2_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE messages ADD conversation_id INT NOT NULL');
        $this->addSql('ALTER TABLE messages ADD lu_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE messages ADD CONSTRAINT FK_DB021E969AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_DB021E969AC0396 ON messages (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE messages DROP CONSTRAINT FK_DB021E969AC0396');
        $this->addSql('ALTER TABLE conversations DROP CONSTRAINT FK_C2521BF1B29A9963');
        $this->addSql('ALTER TABLE conversations DROP CONSTRAINT FK_C2521BF1A02F368D');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP INDEX IDX_DB021E969AC0396');
        $this->addSql('ALTER TABLE messages DROP conversation_id');
        $this->addSql('ALTER TABLE messages DROP lu_at');
    }
}
