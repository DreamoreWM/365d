<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\StatutBonDeCommande;
use App\Enum\StatutPrestation;
use App\Repository\BonDeCommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: BonDeCommandeRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Get(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['bon:read']],
    denormalizationContext: ['groups' => ['bon:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'statut' => 'exact',
    'clientNom' => 'partial',
    'numeroCommande' => 'exact',
])]
class BonDeCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['bon:read', 'prestation:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['bon:read', 'bon:write', 'prestation:read'])]
    private ?string $clientNom = null;

    #[ORM\Column(length: 255)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?string $clientAdresse = null;

    #[ORM\Column(length: 50)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?string $clientTelephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?string $clientEmail = null;

    #[ORM\Column]
    #[Groups(['bon:read', 'bon:write'])]
    private ?\DateTimeImmutable $dateCommande = null;

    #[ORM\OneToMany(mappedBy: 'bonDeCommande', targetEntity: Prestation::class, cascade: ['persist', 'remove'])]
    #[Groups(['bon:read'])]
    private Collection $prestations;

    #[ORM\Column(length: 50, enumType: StatutBonDeCommande::class)]
    #[Groups(['bon:read', 'prestation:read'])]
    private StatutBonDeCommande $statut = StatutBonDeCommande::A_PROGRAMMER;

    #[ORM\Column(type: 'integer')]
    #[Groups(['bon:read'])]
    private int $nombrePrestations = 0;

    #[ORM\ManyToOne(targetEntity: TypePrestation::class)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?TypePrestation $typePrestation = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['bon:read'])]
    private int $nombrePrestationsNecessaires = 0;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['bon:read', 'bon:write', 'prestation:read'])]
    private ?string $numeroCommande = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?string $clientComplementAdresse = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['bon:read', 'bon:write'])]
    private ?\DateTimeImmutable $dateLimiteExecution = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    #[Groups(['bon:read'])]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    #[Groups(['bon:read'])]
    private ?string $longitude = null;

    /** Cached "address that was geocoded" — used to detect when a re-geocode is needed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseGeocodee = null;

    /**
     * Admin-curated correction of the address used for geocoding only.
     * `clientAdresse` stays untouched (factured/displayed as-is); this is the
     * string sent to Nominatim when the original fails (e.g. "rue" → "avenue").
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['bon:read'])]
    private ?string $adresseGpsOverride = null;

    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $latitude): self { $this->latitude = $latitude; return $this; }

    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $longitude): self { $this->longitude = $longitude; return $this; }

    public function getAdresseGeocodee(): ?string { return $this->adresseGeocodee; }
    public function setAdresseGeocodee(?string $adresseGeocodee): self { $this->adresseGeocodee = $adresseGeocodee; return $this; }

    public function getAdresseGpsOverride(): ?string { return $this->adresseGpsOverride; }
    public function setAdresseGpsOverride(?string $v): self { $this->adresseGpsOverride = $v; return $this; }

    public function hasCoordonnees(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }


    public function __construct()
    {
        $this->prestations = new ArrayCollection();
        $this->dateCommande = new \DateTimeImmutable();
        $this->statut = StatutBonDeCommande::A_PROGRAMMER;
        $this->nombrePrestations = 0;
        $this->nombrePrestationsNecessaires = 0;
    }

    public function getNombrePrestationsNecessaires(): int
    {
        return $this->nombrePrestationsNecessaires;
    }

    public function setNombrePrestationsNecessaires(int $nombrePrestationsNecessaires): self
    {
        $this->nombrePrestationsNecessaires = $nombrePrestationsNecessaires;
        return $this;
    }

        public function getTypePrestation(): ?TypePrestation
    {
        return $this->typePrestation;
    }

    public function setTypePrestation(?TypePrestation $typePrestation): self
    {
        $this->typePrestation = $typePrestation;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientNom(): ?string
    {
        return $this->clientNom;
    }

    public function setClientNom(string $clientNom): static
    {
        $this->clientNom = $clientNom;
        return $this;
    }

    public function getClientAdresse(): ?string
    {
        return $this->clientAdresse;
    }

    public function setClientAdresse(string $clientAdresse): static
    {
        $this->clientAdresse = $clientAdresse;
        return $this;
    }

    /**
     * Retourne l'adresse à utiliser pour un lien Google Maps / géocodage.
     * Priorité : override admin (BAN-corrigée) → nettoyage heuristique de clientAdresse.
     */
    public function getAdresseGps(): ?string
    {
        // An admin-curated / BAN-corrected override takes precedence over the raw
        // bon address — the GPS link should open on the verified location, not the
        // original typo'd address.
        if ($this->adresseGpsOverride !== null && $this->adresseGpsOverride !== '') {
            return $this->adresseGpsOverride;
        }

        $adresse = $this->clientAdresse;
        if (!$adresse) {
            return null;
        }

        // Normaliser les retours à la ligne et espaces multiples en un seul espace
        $adresse = preg_replace('/[\r\n]+/', ' ', $adresse);
        $adresse = preg_replace('/\s+/', ' ', trim($adresse));

        // Types de voies courants
        $typesVoie = 'RUE|R|AVENUE|AV|BOULEVARD|BD|BVD|PLACE|PL|IMPASSE|IMP|ALLEE|ALL|CHEMIN|CH|ROUTE|RTE|PASSAGE|PASS|COURS|SQUARE|SQ|CITE|LIEU.DIT|VOIE|SENTIER|ROND.POINT|QUAI';

        // Pattern : numéro + type de voie + nom de voie + (bruit éventuel) + code postal + ville (+ bruit)
        if (preg_match('/^(\d+(?:\s*(?:BIS|TER|[A-Z]))?)\s+(' . $typesVoie . ')\s+(.+?)\s+(\d{5})\s+(\S+(?:\s+\S+)?)/i', $adresse, $matches)) {
            $numero = trim($matches[1]);
            $typeVoie = trim($matches[2]);
            $apresTypeVoie = trim($matches[3]);
            $codePostal = $matches[4];
            $ville = trim($matches[5]);

            // Retirer le nom de résidence/bâtiment du nom de rue
            $motsBatiment = ['PAVILLON', 'RESIDENCE', 'BATIMENT', 'BAT', 'TOUR', 'IMMEUBLE', 'VILLA', 'LOTISSEMENT', 'LOT', 'HAMEAU', 'FOYER', 'ENTREE', 'BLOC', 'GROUPE', 'ENSEMBLE'];
            $pattern = '/\s+(?:' . implode('|', $motsBatiment) . ')\b.*/i';
            $nomRue = preg_replace($pattern, '', $apresTypeVoie);

            // Retirer les infos logement de la ville
            $ville = preg_replace('/\s+LOGEMENT\b.*/i', '', $ville);

            return $numero . ' ' . $typeVoie . ' ' . $nomRue . ' ' . $codePostal . ' ' . $ville;
        }

        // Fallback : retirer les infos logement et noms de bâtiment
        $cleaned = preg_replace('/\s*LOGEMENT\b.*/i', '', $adresse);
        return $cleaned;
    }

    public function getClientTelephone(): ?string
    {
        return $this->clientTelephone;
    }

    public function setClientTelephone(string $clientTelephone): static
    {
        $this->clientTelephone = $clientTelephone;
        return $this;
    }

    public function getClientEmail(): ?string
    {
        return $this->clientEmail;
    }

    public function setClientEmail(?string $clientEmail): static
    {
        $this->clientEmail = $clientEmail;
        return $this;
    }

    public function getDateCommande(): ?\DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(\DateTimeImmutable $dateCommande): static
    {
        $this->dateCommande = $dateCommande;
        return $this;
    }

    /**
     * @return Collection<int, Prestation>
     */
    public function getPrestations(): Collection
    {
        return $this->prestations;
    }

    public function addPrestation(Prestation $prestation): static
    {
        if (!$this->prestations->contains($prestation)) {
            $this->prestations->add($prestation);
            $prestation->setBonDeCommande($this);
        }

        return $this;
    }

    public function removePrestation(Prestation $prestation): static
    {
        if ($this->prestations->removeElement($prestation)) {
            if ($prestation->getBonDeCommande() === $this) {
                $prestation->setBonDeCommande(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return 'Bon #' . $this->id . ' - ' . $this->clientNom;
    }

    public function getStatut(): StatutBonDeCommande
    {
        return $this->statut;
    }

    public function setStatut(StatutBonDeCommande $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    public function getNombrePrestations(): int
    {
        return $this->nombrePrestations;
    }

    public function setNombrePrestations(int $nombrePrestations): self
    {
        $this->nombrePrestations = $nombrePrestations;
        return $this;
    }

    public function updateStatutEtNombrePrestations(): void
    {
        $this->nombrePrestations = $this->prestations->count();

        if ($this->prestations->isEmpty()) {
            $this->statut = StatutBonDeCommande::A_PROGRAMMER;
            return;
        }

        $allTerminees = true;
        $oneProgrammee = false;

        foreach ($this->prestations as $p) {
            if ($p->getStatut() === StatutPrestation::PROGRAMME) {
                $oneProgrammee = true;
                $allTerminees = false;
            } elseif ($p->getStatut() !== StatutPrestation::TERMINE) {
                $allTerminees = false;
            }
        }

        if ($allTerminees) {
            $this->statut = StatutBonDeCommande::TERMINE;
        } elseif ($oneProgrammee) {
            $this->statut = StatutBonDeCommande::PROGRAMME;
        } else {
            $this->statut = StatutBonDeCommande::A_PROGRAMMER;
        }
    }

    public function getNumeroCommande(): ?string
    {
        return $this->numeroCommande;
    }

    public function setNumeroCommande(?string $numeroCommande): self
    {
        $this->numeroCommande = $numeroCommande;
        return $this;
    }

    public function getClientComplementAdresse(): ?string
    {
        return $this->clientComplementAdresse;
    }

    public function setClientComplementAdresse(?string $clientComplementAdresse): self
    {
        $this->clientComplementAdresse = $clientComplementAdresse;
        return $this;
    }

    public function getDateLimiteExecution(): ?\DateTimeImmutable
    {
        return $this->dateLimiteExecution;
    }

    public function setDateLimiteExecution(?\DateTimeImmutable $dateLimiteExecution): self
    {
        $this->dateLimiteExecution = $dateLimiteExecution;
        return $this;
    }

    public function hasNonEffectuee(): bool
    {
        foreach ($this->prestations as $p) {
            if ($p->getStatut() === StatutPrestation::NON_EFFECTUE) {
                return true;
            }
        }
        return false;
    }

    public function isDeadlineProche(int $joursAlerte = 7): bool
    {
        if ($this->dateLimiteExecution === null) {
            return false;
        }
        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->dateLimiteExecution);
        return !$diff->invert && $diff->days <= $joursAlerte;
    }

    public function isDeadlineDepassee(): bool
    {
        if ($this->dateLimiteExecution === null) {
            return false;
        }
        return $this->dateLimiteExecution < new \DateTimeImmutable('today');
    }
}
