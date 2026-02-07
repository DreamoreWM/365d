<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
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

        return $this->prestationRepository->findBy(
            ['employe' => $user],
            ['datePrestation' => 'DESC']
        );
    }
}
