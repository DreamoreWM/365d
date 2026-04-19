<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invalidates the geocoding cache so that every bon is re-resolved through
 * the BAN (api-adresse.data.gouv.fr) on next access. Previous results came
 * from Nominatim + fuzzy voie-type substitution, which sometimes landed in
 * the wrong municipality (e.g. "89 rue Léon Blum Hellemmes" → Wattrelos).
 *
 * Non-destructive: `client_adresse` stays untouched — only cached coords,
 * geocoded-address marker, and auto-set GPS override are cleared.
 */
final class Version20260418180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reset geocoding cache (switch Nominatim → BAN)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE bon_de_commande SET latitude = NULL, longitude = NULL, adresse_geocodee = NULL, adresse_gps_override = NULL');
    }

    public function down(Schema $schema): void
    {
        // No-op: re-hydrating the previous cache is pointless; the next page
        // load will just re-query BAN anyway.
    }
}
