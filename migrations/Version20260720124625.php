<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260720124625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.firebase_uid et rend user.password nullable (authentification Firebase)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD firebase_uid VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ALTER password DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6492FB49151 ON "user" (firebase_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6492FB49151');
        $this->addSql('ALTER TABLE "user" DROP firebase_uid');
        // Échouera si des comptes sans mot de passe (créés via Firebase) existent :
        // il faut les supprimer ou leur affecter un hash avant de revenir en arrière.
        $this->addSql('ALTER TABLE "user" ALTER password SET NOT NULL');
    }
}
