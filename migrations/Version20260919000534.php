<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260919000534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade ADD entry_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE trade ADD target_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE trade ADD volume_in_units DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE trade ADD exits JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade DROP entry_price');
        $this->addSql('ALTER TABLE trade DROP target_price');
        $this->addSql('ALTER TABLE trade DROP volume_in_units');
        $this->addSql('ALTER TABLE trade DROP exits');
    }
}
