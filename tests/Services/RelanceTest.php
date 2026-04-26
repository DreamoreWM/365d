<?php

namespace App\Tests\Services;

use App\Entity\BonDeCommande;
use App\Entity\Relance;
use PHPUnit\Framework\TestCase;

class RelanceTest extends TestCase
{
    // -----------------------------------------------
    // estEnCooldown
    // -----------------------------------------------

    public function testPasEnCooldownParDefaut(): void
    {
        $bon = new BonDeCommande();
        $this->assertFalse($bon->estEnCooldown());
    }

    public function testCooldownActifApresRelance(): void
    {
        $bon = new BonDeCommande();
        $bon->enregistrerRelance(24);

        $this->assertTrue($bon->estEnCooldown());
        $this->assertNotNull($bon->getProchainRelancePossible());
    }

    public function testCooldownExpireSiDatePassee(): void
    {
        $bon = new BonDeCommande();
        // On force une date passée directement
        $bon->setProchainRelancePossible(new \DateTimeImmutable('-1 hour'));

        $this->assertFalse($bon->estEnCooldown());
    }

    public function testCooldownActifSiDateFuture(): void
    {
        $bon = new BonDeCommande();
        $bon->setProchainRelancePossible(new \DateTimeImmutable('+1 hour'));

        $this->assertTrue($bon->estEnCooldown());
    }

    // -----------------------------------------------
    // estPeuReactif
    // -----------------------------------------------

    public function testPasEncorePeuReactifAvec3Relances(): void
    {
        $bon = new BonDeCommande();
        $bon->setNombreRelances(3);

        $this->assertFalse($bon->estPeuReactif());
    }

    public function testPeuReactifAtteintAuSeuilDefaut(): void
    {
        $bon = new BonDeCommande();
        $bon->setNombreRelances(4);

        $this->assertTrue($bon->estPeuReactif());
    }

    public function testPeuReactifAvecSeuilPersonnalise(): void
    {
        $bon = new BonDeCommande();
        $bon->setNombreRelances(2);

        $this->assertTrue($bon->estPeuReactif(2));
        $this->assertFalse($bon->estPeuReactif(3));
    }

    // -----------------------------------------------
    // enregistrerRelance
    // -----------------------------------------------

    public function testEnregistrerRelanceIncrementeCompteur(): void
    {
        $bon = new BonDeCommande();
        $this->assertSame(0, $bon->getNombreRelances());

        $bon->enregistrerRelance();
        $this->assertSame(1, $bon->getNombreRelances());

        $bon->enregistrerRelance();
        $this->assertSame(2, $bon->getNombreRelances());
    }

    public function testEnregistrerRelancePoseUnCooldownDe24hParDefaut(): void
    {
        $bon = new BonDeCommande();
        $avant = new \DateTimeImmutable();
        $bon->enregistrerRelance();
        $apres = new \DateTimeImmutable();

        $cooldown = $bon->getProchainRelancePossible();
        $this->assertNotNull($cooldown);

        // Le cooldown doit être entre +24h par rapport à $avant et $apres
        $this->assertGreaterThanOrEqual($avant->modify('+24 hours'), $cooldown);
        $this->assertLessThanOrEqual($apres->modify('+24 hours'), $cooldown);
    }

    public function testEnregistrerRelanceAvecCooldownPersonnalise(): void
    {
        $bon = new BonDeCommande();
        $avant = new \DateTimeImmutable();
        $bon->enregistrerRelance(48);

        $cooldown = $bon->getProchainRelancePossible();
        $this->assertNotNull($cooldown);
        $this->assertGreaterThan($avant->modify('+47 hours'), $cooldown);
    }

    public function testPeuReactifEstVraiApres4RelancesSuccessives(): void
    {
        $bon = new BonDeCommande();

        for ($i = 0; $i < 4; $i++) {
            // Simuler cooldown expiré entre chaque relance
            $bon->setProchainRelancePossible(new \DateTimeImmutable('-1 second'));
            $bon->enregistrerRelance();
        }

        $this->assertTrue($bon->estPeuReactif());
        $this->assertSame(4, $bon->getNombreRelances());
    }

    // -----------------------------------------------
    // Entité Relance
    // -----------------------------------------------

    public function testRelanceInitialiseDateRelanceAuConstruct(): void
    {
        $avant = new \DateTimeImmutable();
        $relance = new Relance();
        $apres = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($avant, $relance->getDateRelance());
        $this->assertLessThanOrEqual($apres, $relance->getDateRelance());
    }

    public function testRelanceNoteChampsNullableParDefaut(): void
    {
        $relance = new Relance();
        $this->assertNull($relance->getNote());
        $this->assertNull($relance->getAuteur());
    }

    public function testRelanceSettersGetters(): void
    {
        $relance = new Relance();
        $date = new \DateTimeImmutable('2026-04-26 10:00:00');

        $relance->setNote('Test relance');
        $relance->setDateRelance($date);

        $this->assertSame('Test relance', $relance->getNote());
        $this->assertSame($date, $relance->getDateRelance());
    }
}
