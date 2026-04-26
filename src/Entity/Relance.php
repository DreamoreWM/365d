<?php

namespace App\Entity;

use App\Repository\RelanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RelanceRepository::class)]
class Relance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'relances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BonDeCommande $bonDeCommande;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateRelance;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur = null;

    public function __construct()
    {
        $this->dateRelance = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBonDeCommande(): BonDeCommande { return $this->bonDeCommande; }
    public function setBonDeCommande(BonDeCommande $bonDeCommande): self
    {
        $this->bonDeCommande = $bonDeCommande;
        return $this;
    }

    public function getDateRelance(): \DateTimeImmutable { return $this->dateRelance; }
    public function setDateRelance(\DateTimeImmutable $dateRelance): self
    {
        $this->dateRelance = $dateRelance;
        return $this;
    }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }

    public function getAuteur(): ?User { return $this->auteur; }
    public function setAuteur(?User $auteur): self { $this->auteur = $auteur; return $this; }
}
