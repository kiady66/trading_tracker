<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create central_bank table for the rate cycles clock page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE central_bank (id SERIAL NOT NULL, code VARCHAR(20) NOT NULL, rate VARCHAR(30) NOT NULL, angle DOUBLE PRECISION NOT NULL, bias VARCHAR(20) NOT NULL, retracement BOOLEAN NOT NULL, fond VARCHAR(10) NOT NULL, position INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_central_bank_code ON central_bank (code)');
        $this->addSql('COMMENT ON COLUMN central_bank.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE central_bank');
    }
}
