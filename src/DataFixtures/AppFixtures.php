<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // Création des utilisateurs
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email);
            $user->setNom($faker->word());
            $user->setPassword('password'); // attention, pas de hash pour test
            $users[] = $user;
            $manager->persist($user);
        }

        // Création des types de prestation
        $types = [];
        for ($i = 0; $i < 5; $i++) {
            $type = new TypePrestation();
            $type->setNom($faker->word());
            $types[] = $type;
            $manager->persist($type);
        }

        // Création des prestations
        $prestations = [];
        for ($i = 0; $i < 20; $i++) {
            $prestation = new Prestation();
            $prestation->setTypePrestation($faker->randomElement($types));
            $prestations[] = $prestation;
            $prestation->setDatePrestation(
                \DateTimeImmutable::createFromMutable($faker->dateTimeThisYear())
            );
            $prestation->setDescription($faker->word());
            $manager->persist($prestation);
        }

        // Création des bons de commande
        for ($i = 0; $i < 15; $i++) {
            $bon = new BonDeCommande();
            $bon->addPrestation($faker->randomElement($prestations));
            $bon->setClientNom($faker->name());
            $bon->setClientAdresse($faker->address());
            $bon->setClientTelephone($faker->phoneNumber());
            $bon->setClientEmail($faker->email());
            
            // Date de commande
            $bon->setDateCommande(
                \DateTimeImmutable::createFromMutable($faker->dateTimeThisYear())
            );
            $manager->persist($bon);
        }

        $manager->flush();
    }
}
