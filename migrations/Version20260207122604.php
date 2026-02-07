<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260207122604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Assigner le premier utilisateur aux prestations sans employé
        $this->addSql('UPDATE prestation SET employe_id = (SELECT id FROM `user` LIMIT 1) WHERE employe_id IS NULL');
        $this->addSql('ALTER TABLE prestation CHANGE employe_id employe_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE prestation CHANGE employe_id employe_id INT DEFAULT NULL');
    }
}
