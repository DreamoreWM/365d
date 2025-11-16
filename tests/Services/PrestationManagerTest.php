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

        $em->expects($this->any())->method('persist');
        $em->expects($this->any())->method('flush');

        return new PrestationManager($em);
    }

    /*
     * -------------------------------
     * TESTS : updatePrestationStatut
     * -------------------------------
     */

    public function testPrestationStatutProgramme()
    {
        $manager = $this->createManager();

        $p = new Prestation();
        $p->setDatePrestation((new \DateTimeImmutable())->modify('+2 days'));

        $manager->updatePrestationStatut($p);

        $this->assertEquals('programmé', $p->getStatut());
    }

    public function testPrestationStatutEnCours()
    {
        $manager = $this->createManager();

        $p = new Prestation();
        $p->setDatePrestation(new \DateTimeImmutable('today'));

        $manager->updatePrestationStatut($p);

        $this->assertEquals('en cours', $p->getStatut());
    }

    public function testPrestationStatutTermine()
    {
        $manager = $this->createManager();

        $p = new Prestation();
        $p->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));

        $manager->updatePrestationStatut($p);

        $this->assertEquals('terminé', $p->getStatut());
    }

    public function testPrestationNonEffectuee()
    {
        $manager = $this->createManager();

        $prestation = new Prestation();
        $prestation->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));

        // le manager doit détecter que la prestation programmée en retard devient "non effectué"
        $prestation->setStatut('programmé');

        $manager->updatePrestationStatut($prestation);

        $this->assertSame('non effectué', $prestation->getStatut());
    }


    public function testPrestationNonEffectueeNeComptePasDansBon()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(2);
        $bon->setTypePrestation($type);

        // Prestations
        $p1 = new Prestation();
        $p1->setStatut('terminé');

        $p2 = new Prestation();
        $p2->setStatut('programmé');
        $p2->setDatePrestation(new \DateTimeImmutable('-2 days')); // dépassée → non effectuée

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $manager->updateBonDeCommande($bon);

        // ✔ Le bon doit repasser à "à programmer"
        $this->assertEquals('à programmer', $bon->getStatut());

        // ✔ Une seule prestation terminée doit être comptée
        $this->assertEquals(1, $bon->getNombrePrestations());
    }

    public function testPrestationPasseEnNonEffectueSiDateDepassee(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $manager = new PrestationManager($em);

        // Bon de commande
        $bon = new BonDeCommande();
        $bon->setNombrePrestationsNecessaires(1);

        // Prestation programmée pour hier
        $prestation = new Prestation();
        $prestation->setDatePrestation(
            (new \DateTimeImmutable('yesterday'))
        );
        $prestation->setStatut('programmé');

        $bon->addPrestation($prestation);

        // Mise à jour
        $manager->updatePrestationStatut($prestation);
        $manager->updateBonDeCommande($bon);

        // Vérifications
        $this->assertSame(
            'non effectué',
            $prestation->getStatut(),
            "La prestation programmée dans le passé doit devenir 'non effectué'"
        );

        $this->assertSame(
            'à programmer',
            $bon->getStatut(),
            "Le bon doit repasser en 'à programmer' car une prestation est non effectuée"
        );
    }



    /*
     * -------------------------------
     * TESTS : updateBonDeCommande
     * -------------------------------
     */

    public function testBonDeCommandeTermineQuandQuotaAtteint()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(2);
        $bon->setTypePrestation($type);

        $p1 = new Prestation(); $p1->setStatut('terminé');
        $p2 = new Prestation(); $p2->setStatut('terminé');

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('terminé', $bon->getStatut());
        $this->assertEquals(2, $bon->getNombrePrestations());
    }

    public function testBonDeCommandeEnCours()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(3);
        $bon->setTypePrestation($type);

        $p = new Prestation(); 
        $p->setStatut('en cours');

        $bon->addPrestation($p);

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

        $p = new Prestation();
        $p->setStatut('programmé');

        $bon->addPrestation($p);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('programmé', $bon->getStatut());
    }

    public function testBonDeCommandeAProgrammerSiPrestationNonEffectuee()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $p = new Prestation();
        $p->setStatut('non effectué');

        $bon->addPrestation($p);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('à programmer', $bon->getStatut());
        $this->assertEquals(0, $bon->getNombrePrestations());
    }

    public function testBonDeCommandeAProgrammerQuandAucunePrestation()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $manager->updateBonDeCommande($bon);

        $this->assertEquals('à programmer', $bon->getStatut());
    }
}
