<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125133531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade DROP CONSTRAINT fk_7e1a43667a7b643');
        $this->addSql('DROP SEQUENCE result_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE setup_id_seq CASCADE');
        $this->addSql('ALTER TABLE trade_setup DROP CONSTRAINT fk_f1086d3c2d9760');
        $this->addSql('ALTER TABLE trade_setup DROP CONSTRAINT fk_f1086d3cdcdb68e');
        $this->addSql('DROP TABLE setup');
        $this->addSql('DROP TABLE trade_setup');
        $this->addSql('DROP TABLE result');
        $this->addSql('DROP INDEX idx_7e1a43667a7b643');
        $this->addSql('ALTER TABLE trade DROP result_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE result_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE setup_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE setup (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE trade_setup (trade_id INT NOT NULL, setup_id INT NOT NULL, PRIMARY KEY(trade_id, setup_id))');
        $this->addSql('CREATE INDEX idx_f1086d3c2d9760 ON trade_setup (trade_id)');
        $this->addSql('CREATE INDEX idx_f1086d3cdcdb68e ON trade_setup (setup_id)');
        $this->addSql('CREATE TABLE result (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE trade_setup ADD CONSTRAINT fk_f1086d3c2d9760 FOREIGN KEY (trade_id) REFERENCES trade (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_setup ADD CONSTRAINT fk_f1086d3cdcdb68e FOREIGN KEY (setup_id) REFERENCES setup (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade ADD result_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE trade ADD CONSTRAINT fk_7e1a43667a7b643 FOREIGN KEY (result_id) REFERENCES result (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_7e1a43667a7b643 ON trade (result_id)');
    }
}
