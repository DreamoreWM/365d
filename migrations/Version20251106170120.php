<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251106170120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bon_de_commande ADD type_prestation_id INT DEFAULT NULL, ADD nombre_prestations_necessaires INT NOT NULL');
        $this->addSql('ALTER TABLE bon_de_commande ADD CONSTRAINT FK_2C3802E4EEA87261 FOREIGN KEY (type_prestation_id) REFERENCES type_prestation (id)');
        $this->addSql('CREATE INDEX IDX_2C3802E4EEA87261 ON bon_de_commande (type_prestation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bon_de_commande DROP FOREIGN KEY FK_2C3802E4EEA87261');
        $this->addSql('DROP INDEX IDX_2C3802E4EEA87261 ON bon_de_commande');
        $this->addSql('ALTER TABLE bon_de_commande DROP type_prestation_id, DROP nombre_prestations_necessaires');
    }
}
