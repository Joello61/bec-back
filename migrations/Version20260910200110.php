<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910200110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute users.banned_until (bannissement temporaire)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD banned_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP banned_until');
    }
}
