<?php

namespace App\Entity;

use App\Repository\GroupeGeographiqueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupeGeographiqueRepository::class)]
class GroupeGeographique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 7)]
    private ?string $couleur = null;

    #[ORM\Column(type: Types::JSON)]
    private array $villes = [];

    #[ORM\Column(type: Types::JSON)]
    private array $villesData = []; // Stocke {code, nom, contour, latitude, longitude}

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private ?bool $actif = true;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->couleur = $this->generateRandomColor();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = $couleur;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getVilles(): array
    {
        return $this->villes;
    }

    public function setVilles(array $villes): static
    {
        $this->villes = $villes;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addVille(string $ville): static
    {
        if (!in_array($ville, $this->villes)) {
            $this->villes[] = $ville;
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function removeVille(string $ville): static
    {
        $key = array_search($ville, $this->villes);
        if ($key !== false) {
            unset($this->villes[$key]);
            $this->villes = array_values($this->villes);
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function hasVille(string $ville): bool
    {
        return in_array($ville, $this->villes);
    }

    public function getVillesData(): array
    {
        return $this->villesData;
    }

    public function setVillesData(array $villesData): static
    {
        $this->villesData = $villesData;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addVilleData(array $villeData): static
    {
        $this->villesData[] = $villeData;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isActif(): ?bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    private function generateRandomColor(): string
    {
        $colors = ['#3F51B5', '#4CAF50', '#FF9800', '#F44336', '#9C27B0', '#00BCD4', '#FF5722', '#795548'];
        return $colors[array_rand($colors)];
    }
}