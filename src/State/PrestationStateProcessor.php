<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Prestation;
use App\Service\PrestationManager;

class PrestationStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private PrestationManager $prestationManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($data instanceof Prestation) {
            $this->prestationManager->updatePrestationStatut($data);

            if ($data->getBonDeCommande()) {
                $this->prestationManager->updateBonDeCommande($data->getBonDeCommande());
            }
        }

        return $result;
    }
}
