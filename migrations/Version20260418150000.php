<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a per-bon GPS address override — lets admins correct an address
 * (e.g. "rue" → "avenue") for geocoding only, without modifying the bon's
 * client-facing address.
 */
final class Version20260418150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BonDeCommande: ajout de adresse_gps_override pour corriger le géocodage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_de_commande ADD adresse_gps_override VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_de_commande DROP adresse_gps_override');
    }
}
