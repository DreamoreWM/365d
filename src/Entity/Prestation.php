<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use App\State\MyPrestationsProvider;
use App\State\PrestationStateProcessor;
use App\State\TerminatePrestationProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PrestationRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/prestations/my',
            provider: MyPrestationsProvider::class,
            security: "is_granted('ROLE_USER')",
            normalizationContext: ['groups' => ['prestation:read']],
        ),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Get(
            security: "is_granted('ROLE_ADMIN') or object.getEmploye() == user",
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            processor: PrestationStateProcessor::class,
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or object.getEmploye() == user",
            processor: PrestationStateProcessor::class,
        ),
        new Patch(
            uriTemplate: '/prestations/{id}/terminate',
            security: "is_granted('ROLE_ADMIN') or object.getEmploye() == user",
            processor: TerminatePrestationProcessor::class,
            denormalizationContext: ['groups' => ['prestation:terminate']],
        ),
        new Patch(
            uriTemplate: '/prestations/{id}/signature',
            security: "is_granted('ROLE_ADMIN') or object.getEmploye() == user",
            denormalizationContext: ['groups' => ['prestation:signature']],
            processor: PrestationStateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['prestation:read']],
    denormalizationContext: ['groups' => ['prestation:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'statut' => 'exact',
    'employe' => 'exact',
    'bonDeCommande' => 'exact',
])]
#[ApiFilter(DateFilter::class, properties: ['datePrestation'])]
class Prestation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['prestation:read', 'bon:read'])]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\GreaterThanOrEqual(
        value: "today",
        message: "La date de prestation ne peut pas être antérieure à aujourd'hui."
    )]
    #[Groups(['prestation:read', 'prestation:write', 'bon:read'])]
    private ?\DateTimeImmutable $datePrestation = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['prestation:read', 'prestation:write', 'bon:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['prestation:read', 'prestation:write'])]
    private ?User $employe = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    #[Groups(['prestation:read', 'prestation:write'])]
    private ?BonDeCommande $bonDeCommande = null;

    #[ORM\ManyToOne(inversedBy: 'prestations')]
    #[Groups(['prestation:read', 'prestation:write'])]
    private ?TypePrestation $typePrestation = null;

    #[ORM\Column(length: 50, nullable: true, enumType: StatutPrestation::class)]
    #[Groups(['prestation:read', 'bon:read'])]
    private ?StatutPrestation $statut = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['prestation:read', 'prestation:write', 'bon:read'])]
    private ?string $compteRendu = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['prestation:read', 'prestation:signature'])]
    private ?string $signature = null;

    public function getCompteRendu(): ?string
    {
        return $this->compteRendu;
    }

    public function setCompteRendu(?string $compteRendu): static
    {
        $this->compteRendu = $compteRendu;
        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }

    public function getStatut(): ?StatutPrestation
    {
        return $this->statut;
    }

    public function setStatut(StatutPrestation $statut): self
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
