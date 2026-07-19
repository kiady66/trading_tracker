<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create daily_news table for the daily market news wrap';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_news (id SERIAL NOT NULL, date DATE NOT NULL, content_html TEXT NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_daily_news_date ON daily_news (date)');
        $this->addSql('COMMENT ON COLUMN daily_news.date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN daily_news.generated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_news');
    }
}