<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250903121314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE confluence (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE result (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE setup (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE timeframe (id SERIAL NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE trade (id SERIAL NOT NULL, result_id INT DEFAULT NULL, trade_type_id INT DEFAULT NULL, trend_id INT DEFAULT NULL, error_id INT DEFAULT NULL, asset VARCHAR(100) NOT NULL, entry_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, exit_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, watchlist_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_type VARCHAR(20) NOT NULL, risk_percentage DOUBLE PRECISION NOT NULL, initial_rr DOUBLE PRECISION DEFAULT NULL, final_rr DOUBLE PRECISION DEFAULT NULL, gain_rr DOUBLE PRECISION DEFAULT NULL, gain_euro DOUBLE PRECISION DEFAULT NULL, max_risk_euro DOUBLE PRECISION NOT NULL, day VARCHAR(20) DEFAULT NULL, trade_management BOOLEAN NOT NULL, good_trade BOOLEAN DEFAULT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7E1A43667A7B643 ON trade (result_id)');
        $this->addSql('CREATE INDEX IDX_7E1A436636CCD465 ON trade (trade_type_id)');
        $this->addSql('CREATE INDEX IDX_7E1A436665B0AAB2 ON trade (trend_id)');
        $this->addSql('CREATE INDEX IDX_7E1A4366836088D7 ON trade (error_id)');
        $this->addSql('CREATE TABLE trade_timeframe (trade_id INT NOT NULL, timeframe_id INT NOT NULL, PRIMARY KEY(trade_id, timeframe_id))');
        $this->addSql('CREATE INDEX IDX_62362986C2D9760 ON trade_timeframe (trade_id)');
        $this->addSql('CREATE INDEX IDX_623629861F6C835C ON trade_timeframe (timeframe_id)');
        $this->addSql('CREATE TABLE trade_confluence (trade_id INT NOT NULL, confluence_id INT NOT NULL, PRIMARY KEY(trade_id, confluence_id))');
        $this->addSql('CREATE INDEX IDX_2AA9E5FDC2D9760 ON trade_confluence (trade_id)');
        $this->addSql('CREATE INDEX IDX_2AA9E5FDC62D3CC0 ON trade_confluence (confluence_id)');
        $this->addSql('CREATE TABLE trade_setup (trade_id INT NOT NULL, setup_id INT NOT NULL, PRIMARY KEY(trade_id, setup_id))');
        $this->addSql('CREATE INDEX IDX_F1086D3C2D9760 ON trade_setup (trade_id)');
        $this->addSql('CREATE INDEX IDX_F1086D3CDCDB68E ON trade_setup (setup_id)');
        $this->addSql('CREATE TABLE trade_error (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE trade_type (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE trend (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id))');
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
        $this->addSql('ALTER TABLE trade ADD CONSTRAINT FK_7E1A43667A7B643 FOREIGN KEY (result_id) REFERENCES result (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade ADD CONSTRAINT FK_7E1A436636CCD465 FOREIGN KEY (trade_type_id) REFERENCES trade_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade ADD CONSTRAINT FK_7E1A436665B0AAB2 FOREIGN KEY (trend_id) REFERENCES trend (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade ADD CONSTRAINT FK_7E1A4366836088D7 FOREIGN KEY (error_id) REFERENCES trade_error (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_timeframe ADD CONSTRAINT FK_62362986C2D9760 FOREIGN KEY (trade_id) REFERENCES trade (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_timeframe ADD CONSTRAINT FK_623629861F6C835C FOREIGN KEY (timeframe_id) REFERENCES timeframe (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_confluence ADD CONSTRAINT FK_2AA9E5FDC2D9760 FOREIGN KEY (trade_id) REFERENCES trade (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_confluence ADD CONSTRAINT FK_2AA9E5FDC62D3CC0 FOREIGN KEY (confluence_id) REFERENCES confluence (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_setup ADD CONSTRAINT FK_F1086D3C2D9760 FOREIGN KEY (trade_id) REFERENCES trade (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trade_setup ADD CONSTRAINT FK_F1086D3CDCDB68E FOREIGN KEY (setup_id) REFERENCES setup (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE trade DROP CONSTRAINT FK_7E1A43667A7B643');
        $this->addSql('ALTER TABLE trade DROP CONSTRAINT FK_7E1A436636CCD465');
        $this->addSql('ALTER TABLE trade DROP CONSTRAINT FK_7E1A436665B0AAB2');
        $this->addSql('ALTER TABLE trade DROP CONSTRAINT FK_7E1A4366836088D7');
        $this->addSql('ALTER TABLE trade_timeframe DROP CONSTRAINT FK_62362986C2D9760');
        $this->addSql('ALTER TABLE trade_timeframe DROP CONSTRAINT FK_623629861F6C835C');
        $this->addSql('ALTER TABLE trade_confluence DROP CONSTRAINT FK_2AA9E5FDC2D9760');
        $this->addSql('ALTER TABLE trade_confluence DROP CONSTRAINT FK_2AA9E5FDC62D3CC0');
        $this->addSql('ALTER TABLE trade_setup DROP CONSTRAINT FK_F1086D3C2D9760');
        $this->addSql('ALTER TABLE trade_setup DROP CONSTRAINT FK_F1086D3CDCDB68E');
        $this->addSql('DROP TABLE confluence');
        $this->addSql('DROP TABLE result');
        $this->addSql('DROP TABLE setup');
        $this->addSql('DROP TABLE timeframe');
        $this->addSql('DROP TABLE trade');
        $this->addSql('DROP TABLE trade_timeframe');
        $this->addSql('DROP TABLE trade_confluence');
        $this->addSql('DROP TABLE trade_setup');
        $this->addSql('DROP TABLE trade_error');
        $this->addSql('DROP TABLE trade_type');
        $this->addSql('DROP TABLE trend');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
