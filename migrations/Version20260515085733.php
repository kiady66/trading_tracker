<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515085733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ctrader_position_id to trade, api_token to user (with data migration for existing users)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade ADD ctrader_position_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7E1A436615CB86CA ON trade (ctrader_position_id)');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        $this->addSql('ALTER TABLE "user" ADD api_token VARCHAR(64) DEFAULT NULL');
        // Générer un token pour chaque utilisateur existant qui n'en a pas
        $this->addSql('UPDATE "user" SET api_token = encode(gen_random_bytes(32), \'hex\') WHERE api_token IS NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497BA2F5EB ON "user" (api_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP INDEX UNIQ_7E1A436615CB86CA');
        $this->addSql('ALTER TABLE trade DROP ctrader_position_id');
        $this->addSql('DROP INDEX UNIQ_8D93D6497BA2F5EB');
        $this->addSql('ALTER TABLE "user" DROP api_token');
    }
}
