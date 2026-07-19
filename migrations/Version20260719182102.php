<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719182102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add community visibility settings (display name + share toggles) on user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD display_name VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD share_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD share_stats BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD share_open_trades BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD share_closed_trades BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD share_current_month_only BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649D5499347 ON "user" (display_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649D5499347');
        $this->addSql('ALTER TABLE "user" DROP display_name');
        $this->addSql('ALTER TABLE "user" DROP share_enabled');
        $this->addSql('ALTER TABLE "user" DROP share_stats');
        $this->addSql('ALTER TABLE "user" DROP share_open_trades');
        $this->addSql('ALTER TABLE "user" DROP share_closed_trades');
        $this->addSql('ALTER TABLE "user" DROP share_current_month_only');
    }
}
