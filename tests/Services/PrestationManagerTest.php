<?php

namespace App\Tests\Service;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use App\Service\PrestationManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PrestationManagerTest extends TestCase
{
    private function createManager()
    {
        $em = $this->createMock(EntityManagerInterface::class);

        // IMPORTANT : persist() et flush() sont void → il faut expects() au lieu de method()
        $em->expects($this->any())->method('persist');
        $em->expects($this->any())->method('flush');

        return new PrestationManager($em);
    }

    public function testPrestationStatutProgramme()
    {
        $manager = $this->createManager();

        $prestation = new Prestation();
        $prestation->setDatePrestation((new \DateTimeImmutable())->modify('+2 days'));

        $manager->updatePrestationStatut($prestation);

        $this->assertEquals('programmé', $prestation->getStatut());
    }

    public function testPrestationStatutEnCours()
    {
        $manager = $this->createManager();

        $prestation = new Prestation();
        $prestation->setDatePrestation(new \DateTimeImmutable('today'));

        $manager->updatePrestationStatut($prestation);

        $this->assertEquals('en cours', $prestation->getStatut());
    }

    public function testPrestationStatutTermine()
    {
        $manager = $this->createManager();

        $prestation = new Prestation();
        $prestation->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));

        $manager->updatePrestationStatut($prestation);

        $this->assertEquals('terminé', $prestation->getStatut());
    }

    public function testBonDeCommandeTermine()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(2);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setDatePrestation(new \DateTimeImmutable('-2 days'));
        $p1->setStatut('terminé');

        $p2 = new Prestation();
        $p2->setDatePrestation(new \DateTimeImmutable('-1 day'));
        $p2->setStatut('terminé');

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('terminé', $bon->getStatut());
        $this->assertEquals(2, $bon->getNombrePrestations());
        $this->assertEquals(2, $bon->getNombrePrestationsNecessaires());
    }

    public function testBonDeCommandeEnCours()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(3);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setStatut('en cours');

        $bon->addPrestation($p1);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('en cours', $bon->getStatut());
    }

    public function testBonDeCommandeProgramme()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(1);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setDatePrestation(new \DateTimeImmutable('+1 day'));
        $p1->setStatut('programmé');

        $bon->addPrestation($p1);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('programmé', $bon->getStatut());
    }

    public function testBonDeCommandeNonEffectue()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $p1 = new Prestation();
        $p1->setDatePrestation(new \DateTimeImmutable('-1 day'));
        $p1->setStatut('programmé');

        $bon->addPrestation($p1);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('non effectué', $bon->getStatut());
    }

    public function testBonDeCommandeAProgrammer()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('à programmer', $bon->getStatut());
    }
}

