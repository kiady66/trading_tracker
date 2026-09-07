<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the fond column from central_bank (feature removed from the cycles clock)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_bank DROP fond');
    }

    public function down(Schema $schema): void
    {
        // La valeur d'origine est perdue : on recrée la colonne avec son défaut
        $this->addSql('ALTER TABLE central_bank ADD fond VARCHAR(10) DEFAULT \'none\' NOT NULL');
    }
}
