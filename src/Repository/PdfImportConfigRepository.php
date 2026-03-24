<?php

namespace App\Repository;

use App\Entity\PdfImportConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PdfImportConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PdfImportConfig::class);
    }

    /** @return PdfImportConfig[] */
    public function findActifsByPriorite(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.actif = true')
            ->orderBy('c.priorite', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
