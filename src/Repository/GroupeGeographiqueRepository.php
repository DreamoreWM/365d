<?php

namespace App\Repository;

use App\Entity\GroupeGeographique;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupeGeographique>
 */
class GroupeGeographiqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupeGeographique::class);
    }

    public function save(GroupeGeographique $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(GroupeGeographique $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouve tous les groupes actifs
     */
    public function findAllActifs(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('g.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un groupe contenant une ville spécifique
     */
    public function findByVille(string $ville): array
    {
        return $this->createQueryBuilder('g')
            ->where('JSON_CONTAINS(g.villes, :ville) = 1')
            ->setParameter('ville', json_encode($ville))
            ->andWhere('g.actif = :actif')
            ->setParameter('actif', true)
            ->getQuery()
            ->getResult();
    }
}