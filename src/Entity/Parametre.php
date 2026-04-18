<?php

namespace App\Entity;

use App\Repository\ParametreRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ParametreRepository::class)]
#[ORM\Table(name: 'parametre')]
class Parametre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $cle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $valeur = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $libelle = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $type = 'string';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ordre = 0;

    public function getId(): ?int { return $this->id; }

    public function getCle(): ?string { return $this->cle; }
    public function setCle(string $cle): self { $this->cle = $cle; return $this; }

    public function getValeur(): ?string { return $this->valeur; }
    public function setValeur(?string $valeur): self { $this->valeur = $valeur; return $this; }

    public function getLibelle(): ?string { return $this->libelle; }
    public function setLibelle(?string $libelle): self { $this->libelle = $libelle; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): self { $this->type = $type; return $this; }

    public function getOrdre(): ?int { return $this->ordre; }
    public function setOrdre(?int $ordre): self { $this->ordre = $ordre; return $this; }
}
