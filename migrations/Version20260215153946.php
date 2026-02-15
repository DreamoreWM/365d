<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260215153946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs

        // Note: date_limite_execution and client_email nullable were already added in a previous failed migration attempt

        // Update NULL employe_id values before making the column NOT NULL
        $this->addSql('UPDATE prestation SET employe_id = (SELECT id FROM `user` LIMIT 1) WHERE employe_id IS NULL');

        // Add missing columns to prestation
        $this->addSql('ALTER TABLE prestation ADD compte_rendu LONGTEXT DEFAULT NULL, ADD valeurs_champs_personnalises JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', ADD infos_intervention JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', CHANGE employe_id employe_id INT NOT NULL');

        // Add missing columns to type_prestation
        $this->addSql('ALTER TABLE type_prestation ADD code VARCHAR(50) DEFAULT NULL, ADD champs_personnalises JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');

        // Add missing column to user
        $this->addSql('ALTER TABLE user ADD token_valid_after DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

        // Note: This will NOT drop date_limite_execution or change client_email back
        // as they were added in a previous migration attempt

        $this->addSql('ALTER TABLE prestation DROP compte_rendu, DROP valeurs_champs_personnalises, DROP infos_intervention, CHANGE employe_id employe_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE type_prestation DROP code, DROP champs_personnalises');
        $this->addSql('ALTER TABLE `user` DROP token_valid_after');
    }
}
