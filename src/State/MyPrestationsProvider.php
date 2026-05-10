<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Enum\StatutPrestation;
use App\Repository\PrestationRepository;
use Symfony\Bundle\SecurityBundle\Security;

class MyPrestationsProvider implements ProviderInterface
{
    public function __construct(
        private PrestationRepository $prestationRepository,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user) {
            return [];
        }

        return $this->prestationRepository->createQueryBuilder('p')
            ->where('p.employe = :user')
            ->andWhere('p.statut != :brouillon')
            ->setParameter('user', $user)
            ->setParameter('brouillon', StatutPrestation::BROUILLON->value)
            ->orderBy('p.datePrestation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
