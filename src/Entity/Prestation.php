<?php

namespace App\Entity;

use App\Repository\PrestationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrestationRepository::class)]
class Prestation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\GreaterThanOrEqual(
        value: "today",
        message: "La date de prestation ne peut pas être antérieure à aujourd’hui."
    )]
    private ?\DateTimeImmutable $datePrestation = null;

    #[ORM\Column(type: 'text')]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    private ?User $employe = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    private ?BonDeCommande $bonDeCommande = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    private ?TypePrestation $typePrestation = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signature = null;

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        return $this;
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


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDatePrestation(): ?\DateTimeImmutable
    {
        return $this->datePrestation;
    }

    public function setDatePrestation(\DateTimeImmutable $datePrestation): static
    {
        $this->datePrestation = $datePrestation;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getEmploye(): ?User
    {
        return $this->employe;
    }

    public function setEmploye(?User $employe): static
    {
        $this->employe = $employe;
        return $this;
    }

    public function getBonDeCommande(): ?BonDeCommande
    {
        return $this->bonDeCommande;
    }

    public function setBonDeCommande(?BonDeCommande $bonDeCommande): static
    {
        $this->bonDeCommande = $bonDeCommande;
        return $this;
    }

    public function getTypePrestation(): ?TypePrestation
    {
        return $this->typePrestation;
    }

    public function setTypePrestation(?TypePrestation $typePrestation): static
    {
        $this->typePrestation = $typePrestation;
        return $this;
    }

    public function __toString(): string
    {
        return $this->typePrestation ? $this->typePrestation->getNom() : 'Prestation #' . $this->id;
    }
}
