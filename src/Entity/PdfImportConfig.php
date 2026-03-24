<?php

namespace App\Entity;

use App\Repository\PdfImportConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PdfImportConfigRepository::class)]
class PdfImportConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $nom = '';

    /** Texte présent dans le PDF pour identifier ce bailleur (ex: "PARIS HABITAT") */
    #[ORM\Column(length: 255)]
    private string $identifiantTexte = '';

    /**
     * JSON : liste de mappings champ → ligne
     * [
     *   {"dbField": "clientNom", "label": "Nom client", "strategy": "line_index", "lineIndex": 5},
     *   {"dbField": "clientTelephone", "strategy": "line_index_regex", "lineIndex": 6, "regex": "/(0[0-9 ]{9})/", "regexGroup": 1},
     *   {"dbField": "dateLimiteExecution", "strategy": "line_index_date", "lineIndex": 3},
     *   {"dbField": "typePrestation", "strategy": "type_prestation_code"},
     * ]
     */
    #[ORM\Column(type: 'json')]
    private array $fieldMappings = [];

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: 'integer')]
    private int $priorite = 100;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getIdentifiantTexte(): string { return $this->identifiantTexte; }
    public function setIdentifiantTexte(string $t): static { $this->identifiantTexte = $t; return $this; }

    public function getFieldMappings(): array { return $this->fieldMappings; }
    public function setFieldMappings(array $m): static { $this->fieldMappings = $m; return $this; }

    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }

    public function getPriorite(): int { return $this->priorite; }
    public function setPriorite(int $p): static { $this->priorite = $p; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): static { $this->updatedAt = $d; return $this; }

    public function __toString(): string { return $this->nom; }
}
