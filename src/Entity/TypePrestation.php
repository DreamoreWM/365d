<?php

namespace App\Entity;

use App\Repository\TypePrestationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypePrestationRepository::class)]
class TypePrestation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(type: 'integer')]
    private ?int $nombrePrestationsNecessaires = 1;

    #[ORM\OneToMany(mappedBy: 'typePrestation', targetEntity: Prestation::class)]
    private Collection $prestations;

    public function __construct()
    {
        $this->prestations = new ArrayCollection();
    }



    public function getNombrePrestationsNecessaires(): ?int
    {
        return $this->nombrePrestationsNecessaires;
    }

    public function setNombrePrestationsNecessaires(int $nombrePrestationsNecessaires): self
    {
        $this->nombrePrestationsNecessaires = $nombrePrestationsNecessaires;
        return $this;
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
            $prestation->setTypePrestation($this);
        }

        return $this;
    }

    public function removePrestation(Prestation $prestation): static
    {
        if ($this->prestations->removeElement($prestation)) {
            if ($prestation->getTypePrestation() === $this) {
                $prestation->setTypePrestation(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
