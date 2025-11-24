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

    /**
     * Test que les statuts 'non effectué' et 'terminé' sont préservés
     * lors d'appels successifs à updatePrestationStatut
     */
    public function testPrestationStatutNonEffectueEtTermineRestentStables()
    {
        $manager = $this->createManager();

        // Test 1: 'non effectué' doit rester 'non effectué'
        $p1 = new Prestation();
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));
        $p1->setStatut('non effectué');

        $manager->updatePrestationStatut($p1);
        $this->assertEquals('non effectué', $p1->getStatut(), "Le statut 'non effectué' doit être préservé");

        // Deuxième appel pour vérifier la stabilité
        $manager->updatePrestationStatut($p1);
        $this->assertEquals('non effectué', $p1->getStatut(), "Le statut 'non effectué' doit rester stable après plusieurs appels");

        // Test 2: 'terminé' doit rester 'terminé'
        $p2 = new Prestation();
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-3 days'));
        $p2->setStatut('terminé');

        $manager->updatePrestationStatut($p2);
        $this->assertEquals('terminé', $p2->getStatut(), "Le statut 'terminé' doit être préservé");

        // Deuxième appel pour vérifier la stabilité
        $manager->updatePrestationStatut($p2);
        $this->assertEquals('terminé', $p2->getStatut(), "Le statut 'terminé' doit rester stable après plusieurs appels");
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

        // IMPORTANT: Il faut d'abord mettre à jour le statut de la prestation dépassée
        $manager->updatePrestationStatut($p2);
        
        // Ensuite mettre à jour le bon
        $manager->updateBonDeCommande($bon);

        // ✔ La prestation p2 doit être passée à "non effectué"
        $this->assertEquals('non effectué', $p2->getStatut());

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

    /**
     * Test du scénario complet de double exécution du cron
     * pour vérifier que les statuts ne changent pas incorrectement
     */
    public function testDoubleExecutionCronStatutsStables()
    {
        $manager = $this->createManager();

        // Création d'un bon avec plusieurs prestations
        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(3);
        $bon->setTypePrestation($type);

        // Prestation 1: programmée dans le futur
        $p1 = new Prestation();
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('+5 days'));
        $p1->setStatut('programmé');

        // Prestation 2: programmée hier (va devenir non effectué)
        $p2 = new Prestation();
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-1 day'));
        $p2->setStatut('programmé');

        // Prestation 3: aujourd'hui (va devenir en cours)
        $p3 = new Prestation();
        $p3->setDatePrestation(new \DateTimeImmutable('today'));
        $p3->setStatut('programmé');

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);
        $bon->addPrestation($p3);

        // === PREMIÈRE EXÉCUTION DU CRON ===
        $manager->updatePrestationStatut($p1);
        $manager->updatePrestationStatut($p2);
        $manager->updatePrestationStatut($p3);
        $manager->updateBonDeCommande($bon);

        // Vérifications après première exécution
        $this->assertEquals('programmé', $p1->getStatut(), "P1 doit rester programmé (date future)");
        $this->assertEquals('non effectué', $p2->getStatut(), "P2 doit devenir non effectué (date passée)");
        $this->assertEquals('en cours', $p3->getStatut(), "P3 doit devenir en cours (aujourd'hui)");

        // === DEUXIÈME EXÉCUTION DU CRON ===
        $manager->updatePrestationStatut($p1);
        $manager->updatePrestationStatut($p2);
        $manager->updatePrestationStatut($p3);
        $manager->updateBonDeCommande($bon);

        // Vérifications après deuxième exécution - les statuts doivent rester stables
        $this->assertEquals('programmé', $p1->getStatut(), "P1 doit toujours être programmé");
        $this->assertEquals('non effectué', $p2->getStatut(), "P2 doit rester non effectué et ne pas passer à terminé");
        $this->assertEquals('en cours', $p3->getStatut(), "P3 doit rester en cours");
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

    public function testCronUpdateAllBonDeCommande()
    {
        // 1) Fake repository qui étend EntityRepository
        $fakeRepo = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findAll'])
            ->getMock();

        // 2) Préparation des données
        $bon = new BonDeCommande();
        $type = new TypePrestation();
        $type->setNombrePrestationsNecessaires(1);
        $bon->setTypePrestation($type);

        // Prestation future -> programmé
        $p1 = new Prestation();
        $p1->setStatut('programmé');
        $p1->setDatePrestation((new \DateTimeImmutable())->modify('+2 days'));

        // Prestation passée -> doit devenir "non effectué"
        $p2 = new Prestation();
        $p2->setStatut('programmé');
        $p2->setDatePrestation((new \DateTimeImmutable())->modify('-1 day'));

        $bon->addPrestation($p1);
        $bon->addPrestation($p2);

        // Le repository renvoie notre bon
        $fakeRepo->method('findAll')->willReturn([$bon]);

        // 3) Mock EntityManager
        $em = $this->createMock(EntityManagerInterface::class);

        $em->method('getRepository')->willReturn($fakeRepo);

        // Persist / flush neutres
        $em->method('persist');
        $em->method('flush');

        // 4) Manager
        $manager = new PrestationManager($em);

        // 5) Execution du cron
        $manager->updateAllBonDeCommande();

        // 6) Vérifications
        $this->assertEquals('programmé', $p1->getStatut());
        $this->assertEquals('non effectué', $p2->getStatut());
        $this->assertEquals('à programmer', $bon->getStatut());
    }


}
