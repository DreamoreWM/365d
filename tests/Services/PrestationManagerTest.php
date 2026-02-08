<?php

namespace App\Tests\Service;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use App\Enum\StatutPrestation;
use App\Enum\StatutBonDeCommande;
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

        $this->assertEquals(StatutPrestation::PROGRAMME, $p->getStatut());
    }

    public function testPrestationStatutEnCours()
    {
        $manager = $this->createManager();

        $p = new Prestation();
        $p->setDatePrestation(new \DateTimeImmutable('today'));

        $manager->updatePrestationStatut($p);

        $this->assertEquals(StatutPrestation::EN_COURS, $p->getStatut());
    }

    public function testPrestationStatutTermine()
    {
        $manager = $this->createManager();

        $p = new Prestation();
        $p->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));

        $manager->updatePrestationStatut($p);

        $this->assertEquals(StatutPrestation::TERMINE, $p->getStatut());
    }

    public function testPrestationNonEffectuee()
    {
        $manager = $this->createManager();

        $prestation = new Prestation();
        $prestation->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));

        $prestation->setStatut(StatutPrestation::PROGRAMME);

        $manager->updatePrestationStatut($prestation);

        $this->assertSame(StatutPrestation::NON_EFFECTUE, $prestation->getStatut());
    }

    public function testPrestationStatutNonEffectueEtTermineRestentStables()
    {
        $manager = $this->createManager();

        // Test 1: NON_EFFECTUE doit rester NON_EFFECTUE
        $p1 = new Prestation();
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));
        $p1->setStatut(StatutPrestation::NON_EFFECTUE);

        $manager->updatePrestationStatut($p1);
        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p1->getStatut(), "Le statut NON_EFFECTUE doit être préservé");

        $manager->updatePrestationStatut($p1);
        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p1->getStatut(), "Le statut NON_EFFECTUE doit rester stable après plusieurs appels");

        // Test 2: TERMINE doit rester TERMINE
        $p2 = new Prestation();
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));
        $p2->setStatut(StatutPrestation::TERMINE);

        $manager->updatePrestationStatut($p2);
        $this->assertEquals(StatutPrestation::TERMINE, $p2->getStatut(), "Le statut TERMINE doit être préservé");

        $manager->updatePrestationStatut($p2);
        $this->assertEquals(StatutPrestation::TERMINE, $p2->getStatut(), "Le statut TERMINE doit rester stable après plusieurs appels");
    }

    public function testPrestationNonEffectueeNeComptePasDansBon()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(2);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setStatut(StatutPrestation::TERMINE);

        $p2 = new Prestation();
        $p2->setStatut(StatutPrestation::PROGRAMME);
        $p2->setDatePrestation(new \DateTimeImmutable('-2 days'));

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $manager->updatePrestationStatut($p2);
        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p2->getStatut());
        $this->assertEquals(StatutBonDeCommande::A_PROGRAMMER, $bon->getStatut());
        $this->assertEquals(1, $bon->getNombrePrestations());
    }

    public function testPrestationPasseEnNonEffectueSiDateDepassee(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $manager = new PrestationManager($em);

        $bon = new BonDeCommande();
        $bon->setNombrePrestationsNecessaires(1);

        $prestation = new Prestation();
        $prestation->setDatePrestation(new \DateTimeImmutable('yesterday'));
        $prestation->setStatut(StatutPrestation::PROGRAMME);

        $bon->addPrestation($prestation);

        $manager->updatePrestationStatut($prestation);
        $manager->updateBonDeCommande($bon);

        $this->assertSame(StatutPrestation::NON_EFFECTUE, $prestation->getStatut());
        $this->assertSame(StatutBonDeCommande::A_PROGRAMMER, $bon->getStatut());
    }

    public function testDoubleExecutionCronStatutsStables()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(3);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('+5 days'));
        $p1->setStatut(StatutPrestation::PROGRAMME);

        $p2 = new Prestation();
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-1 day'));
        $p2->setStatut(StatutPrestation::PROGRAMME);

        $p3 = new Prestation();
        $p3->setDatePrestation(new \DateTimeImmutable('today'));
        $p3->setStatut(StatutPrestation::PROGRAMME);

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);
        $bon->addPrestation($p3);

        // === PREMIÈRE EXÉCUTION ===
        $manager->updatePrestationStatut($p1);
        $manager->updatePrestationStatut($p2);
        $manager->updatePrestationStatut($p3);
        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutPrestation::PROGRAMME, $p1->getStatut());
        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p2->getStatut());
        $this->assertEquals(StatutPrestation::EN_COURS, $p3->getStatut());

        // === DEUXIÈME EXÉCUTION ===
        $manager->updatePrestationStatut($p1);
        $manager->updatePrestationStatut($p2);
        $manager->updatePrestationStatut($p3);
        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutPrestation::PROGRAMME, $p1->getStatut());
        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p2->getStatut());
        $this->assertEquals(StatutPrestation::EN_COURS, $p3->getStatut());
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

        $p1 = new Prestation(); $p1->setStatut(StatutPrestation::TERMINE);
        $p2 = new Prestation(); $p2->setStatut(StatutPrestation::TERMINE);

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutBonDeCommande::TERMINE, $bon->getStatut());
        $this->assertEquals(2, $bon->getNombrePrestations());
    }

    public function testBonDeCommandeTermineQuandQuotaAtteintMemeAvecNonEffectuee()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(2);
        $bon->setTypePrestation($type);

        $p1 = new Prestation(); $p1->setStatut(StatutPrestation::TERMINE);
        $p2 = new Prestation(); $p2->setStatut(StatutPrestation::TERMINE);
        $p3 = new Prestation(); $p3->setStatut(StatutPrestation::NON_EFFECTUE);

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);
        $bon->addPrestation($p3);

        $manager->updateBonDeCommande($bon);

        // Le quota est atteint (2 terminées sur 2 nécessaires), terminé malgré la non effectuée
        $this->assertEquals(StatutBonDeCommande::TERMINE, $bon->getStatut());
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
        $p->setStatut(StatutPrestation::EN_COURS);

        $bon->addPrestation($p);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutBonDeCommande::EN_COURS, $bon->getStatut());
    }

    public function testBonDeCommandeProgramme()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(1);
        $bon->setTypePrestation($type);

        $p = new Prestation();
        $p->setStatut(StatutPrestation::PROGRAMME);

        $bon->addPrestation($p);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutBonDeCommande::PROGRAMME, $bon->getStatut());
    }

    public function testBonDeCommandeAProgrammerSiPrestationNonEffectuee()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $p = new Prestation();
        $p->setStatut(StatutPrestation::NON_EFFECTUE);

        $bon->addPrestation($p);

        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutBonDeCommande::A_PROGRAMMER, $bon->getStatut());
        $this->assertEquals(0, $bon->getNombrePrestations());
    }

    public function testBonDeCommandeAProgrammerQuandAucunePrestation()
    {
        $manager = $this->createManager();

        $bon = new BonDeCommande();

        $manager->updateBonDeCommande($bon);

        $this->assertEquals(StatutBonDeCommande::A_PROGRAMMER, $bon->getStatut());
    }

    public function testCronUpdateAllBonDeCommande()
    {
        $fakeRepo = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findAll'])
            ->getMock();

        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(1);
        $bon->setTypePrestation($type);

        $p1 = new Prestation();
        $p1->setStatut(StatutPrestation::PROGRAMME);
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('+2 days'));

        $p2 = new Prestation();
        $p2->setStatut(StatutPrestation::PROGRAMME);
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-1 day'));

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        $fakeRepo->method('findAll')->willReturn([$bon]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($fakeRepo);
        $em->method('persist');
        $em->method('flush');

        $manager = new PrestationManager($em);
        $manager->updateAllBonDeCommande();

        $this->assertEquals(StatutPrestation::PROGRAMME, $p1->getStatut());
        $this->assertEquals(StatutPrestation::NON_EFFECTUE, $p2->getStatut());
        // Le bon a une prestation programmée, donc il est PROGRAMME (pas A_PROGRAMMER)
        // La non effectuée ne bloque pas le statut tant qu'il y a du travail planifié
        $this->assertEquals(StatutBonDeCommande::PROGRAMME, $bon->getStatut());
    }
}
