<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the parametre table, prestation creneau/duree/trajet fields,
 * and bon_de_commande lat/lng cache for tour optimization.
 */
final class Version20260418120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning: parametres, creneau/duree prestation, lat/lng bon (route optimization)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parametre (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(100) NOT NULL, valeur LONGTEXT DEFAULT NULL, libelle VARCHAR(150) DEFAULT NULL, type VARCHAR(30) DEFAULT NULL, ordre INT DEFAULT NULL, UNIQUE INDEX UNIQ_PARAMETRE_CLE (cle), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE prestation ADD creneau VARCHAR(20) DEFAULT NULL, ADD duree_minutes INT DEFAULT NULL, ADD duree_trajet_minutes INT DEFAULT NULL');

        $this->addSql('ALTER TABLE bon_de_commande ADD latitude NUMERIC(10, 7) DEFAULT NULL, ADD longitude NUMERIC(10, 7) DEFAULT NULL, ADD adresse_geocodee VARCHAR(255) DEFAULT NULL');

        // Seed default parameters so the settings page is usable on first visit
        $defaults = [
            ['company_address',         '',       'Adresse de la société',                   'address',  0],
            ['company_latitude',        '',       'Latitude société (auto)',                 'readonly', 1],
            ['company_longitude',       '',       'Longitude société (auto)',                'readonly', 2],
            ['heure_debut_journee',     '08:00',  'Heure de départ du dépôt',                'time',     3],
            ['heure_premiere_prestation','09:00', 'Heure de la 1ère prestation',             'time',     4],
            ['heure_pause_debut',       '12:00',  'Début pause déjeuner',                    'time',     5],
            ['heure_pause_fin',         '12:30',  'Fin pause déjeuner',                      'time',     6],
            ['heure_fin_journee',       '15:00',  'Heure de fin de journée',                 'time',     7],
            ['duree_defaut_minutes',    '30',     'Durée par défaut d\'une prestation (min)', 'integer', 8],
            ['vitesse_moyenne_kmh',     '40',     'Vitesse moyenne de trajet (km/h)',        'integer',  9],
            ['trajet_facteur_detour',   '1.3',    'Facteur de détour (vol d\'oiseau → route)','float',  10],
        ];
        foreach ($defaults as [$cle, $valeur, $libelle, $type, $ordre]) {
            $this->addSql(
                'INSERT INTO parametre (cle, valeur, libelle, type, ordre) VALUES (:cle, :valeur, :libelle, :type, :ordre)',
                ['cle' => $cle, 'valeur' => $valeur, 'libelle' => $libelle, 'type' => $type, 'ordre' => $ordre],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_de_commande DROP latitude, DROP longitude, DROP adresse_geocodee');
        $this->addSql('ALTER TABLE prestation DROP creneau, DROP duree_minutes, DROP duree_trajet_minutes');
        $this->addSql('DROP TABLE parametre');
    }
}
