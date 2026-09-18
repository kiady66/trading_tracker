<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trade.stop_losses (JSON history of stop loss prices, fed by the cTrader bots)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade ADD stop_losses JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trade DROP stop_losses');
    }
}
