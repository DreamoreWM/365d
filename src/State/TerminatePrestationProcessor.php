<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Prestation;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TerminatePrestationProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PrestationManager $prestationManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Prestation) {
            throw new BadRequestHttpException('Invalid entity.');
        }

        $data->setStatut('terminé');
        $this->em->flush();

        if ($data->getBonDeCommande()) {
            $this->prestationManager->updateBonDeCommande($data->getBonDeCommande());
        }

        return $data;
    }
}
