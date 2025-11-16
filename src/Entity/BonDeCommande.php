<?php

namespace App\Entity;

use App\Repository\BonDeCommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BonDeCommandeRepository::class)]
class BonDeCommande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $clientNom = null;

    #[ORM\Column(length: 255)]
    private ?string $clientAdresse = null;

    #[ORM\Column(length: 50)]
    private ?string $clientTelephone = null;

    #[ORM\Column(length: 180)]
    private ?string $clientEmail = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCommande = null;

    #[ORM\OneToMany(mappedBy: 'bonDeCommande', targetEntity: Prestation::class, cascade: ['persist', 'remove'])]
    private Collection $prestations;

    #[ORM\Column(length: 50)]
    private ?string $statut = 'à programmer';

    #[ORM\Column(type: 'integer')]
    private int $nombrePrestations = 0;

    #[ORM\ManyToOne(targetEntity: TypePrestation::class)]
    private ?TypePrestation $typePrestation = null;

    #[ORM\Column(type: 'integer')]
    private int $nombrePrestationsNecessaires = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroCommande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientComplementAdresse = null;

    
    public function __construct()
    {
        $this->prestations = new ArrayCollection();
        $this->dateCommande = new \DateTimeImmutable();
        $this->statut = 'à programmer'; // 👈 FIX : statut par défaut correct
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

    public function setClientEmail(string $clientEmail): static
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

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
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
            $this->statut = 'à programmer';
            return;
        }

        $allTerminees = true;
        $oneProgrammee = false;

        foreach ($this->prestations as $p) {
            if ($p->getStatut() === 'programmé') {
                $oneProgrammee = true;
                $allTerminees = false;
            } elseif ($p->getStatut() !== 'terminé') {
                $allTerminees = false;
            }
        }

        if ($allTerminees) {
            $this->statut = 'terminé';
        } elseif ($oneProgrammee) {
            $this->statut = 'programmé';
        } else {
            $this->statut = 'à programmer';
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
}
