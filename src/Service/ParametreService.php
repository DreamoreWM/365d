<?php

namespace App\Service;

use App\Entity\Parametre;
use App\Repository\ParametreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralizes access to application-wide settings stored in the `parametre` table.
 * Provides a single source of truth for defaults so the rest of the codebase never
 * needs to hardcode values like office hours or default prestation duration.
 */
class ParametreService
{
    public const COMPANY_ADDRESS         = 'company_address';
    public const COMPANY_LATITUDE        = 'company_latitude';
    public const COMPANY_LONGITUDE       = 'company_longitude';
    public const HEURE_DEBUT_JOURNEE     = 'heure_debut_journee';
    public const HEURE_PREMIERE          = 'heure_premiere_prestation';
    public const HEURE_PAUSE_DEBUT       = 'heure_pause_debut';
    public const HEURE_PAUSE_FIN         = 'heure_pause_fin';
    public const HEURE_FIN_JOURNEE       = 'heure_fin_journee';
    public const DUREE_DEFAUT_MINUTES    = 'duree_defaut_minutes';
    public const VITESSE_MOYENNE_KMH     = 'vitesse_moyenne_kmh';
    public const TRAJET_FACTEUR_DETOUR   = 'trajet_facteur_detour';

    /** Defaults applied when a key is missing from the DB. */
    private const DEFAULTS = [
        self::COMPANY_ADDRESS         => '',
        self::COMPANY_LATITUDE        => '',
        self::COMPANY_LONGITUDE       => '',
        self::HEURE_DEBUT_JOURNEE     => '08:00',
        self::HEURE_PREMIERE          => '09:00',
        self::HEURE_PAUSE_DEBUT       => '12:00',
        self::HEURE_PAUSE_FIN         => '12:30',
        self::HEURE_FIN_JOURNEE       => '15:00',
        self::DUREE_DEFAUT_MINUTES    => '30',
        self::VITESSE_MOYENNE_KMH     => '40',
        self::TRAJET_FACTEUR_DETOUR   => '1.3',
    ];

    /** Cache populated lazily — survives the request, refreshed on set(). */
    private ?array $cache = null;

    public function __construct(
        private ParametreRepository $repo,
        private EntityManagerInterface $em,
    ) {}

    public function get(string $cle): ?string
    {
        $this->loadCache();
        return $this->cache[$cle] ?? self::DEFAULTS[$cle] ?? null;
    }

    public function getInt(string $cle): int
    {
        return (int) ($this->get($cle) ?? 0);
    }

    public function getFloat(string $cle): float
    {
        return (float) ($this->get($cle) ?? 0.0);
    }

    public function set(string $cle, ?string $valeur): void
    {
        $p = $this->repo->findByCle($cle) ?? (new Parametre())->setCle($cle);
        $p->setValeur($valeur);
        $this->em->persist($p);
        $this->em->flush();
        $this->cache = null;
    }

    /**
     * @return Parametre[] All parameters with defaults filled in for missing keys.
     */
    public function listAll(): array
    {
        $existing = [];
        foreach ($this->repo->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']) as $p) {
            $existing[$p->getCle()] = $p;
        }
        $result = [];
        $ordre = 0;
        foreach (self::DEFAULTS as $cle => $defaultVal) {
            if (isset($existing[$cle])) {
                $result[] = $existing[$cle];
            } else {
                $p = (new Parametre())
                    ->setCle($cle)
                    ->setValeur($defaultVal)
                    ->setLibelle($this->labelFor($cle))
                    ->setType($this->typeFor($cle))
                    ->setOrdre($ordre);
                $result[] = $p;
            }
            $ordre++;
        }
        return $result;
    }

    public function labelFor(string $cle): string
    {
        return match ($cle) {
            self::COMPANY_ADDRESS       => 'Adresse de la société',
            self::COMPANY_LATITUDE      => 'Latitude société (auto)',
            self::COMPANY_LONGITUDE     => 'Longitude société (auto)',
            self::HEURE_DEBUT_JOURNEE   => 'Heure de départ du dépôt',
            self::HEURE_PREMIERE        => 'Heure de la 1ère prestation',
            self::HEURE_PAUSE_DEBUT     => 'Début pause déjeuner',
            self::HEURE_PAUSE_FIN       => 'Fin pause déjeuner',
            self::HEURE_FIN_JOURNEE     => 'Heure de fin de journée',
            self::DUREE_DEFAUT_MINUTES  => 'Durée par défaut d\'une prestation (min)',
            self::VITESSE_MOYENNE_KMH   => 'Vitesse moyenne de trajet (km/h)',
            self::TRAJET_FACTEUR_DETOUR => 'Facteur de détour (vol d\'oiseau → route)',
            default => $cle,
        };
    }

    public function typeFor(string $cle): string
    {
        return match ($cle) {
            self::COMPANY_ADDRESS => 'address',
            self::COMPANY_LATITUDE, self::COMPANY_LONGITUDE => 'readonly',
            self::HEURE_DEBUT_JOURNEE, self::HEURE_PREMIERE,
            self::HEURE_PAUSE_DEBUT, self::HEURE_PAUSE_FIN,
            self::HEURE_FIN_JOURNEE => 'time',
            self::DUREE_DEFAUT_MINUTES, self::VITESSE_MOYENNE_KMH => 'integer',
            self::TRAJET_FACTEUR_DETOUR => 'float',
            default => 'string',
        };
    }

    private function loadCache(): void
    {
        if ($this->cache !== null) return;
        $this->cache = [];
        foreach ($this->repo->findAll() as $p) {
            $this->cache[$p->getCle()] = $p->getValeur();
        }
    }
}
