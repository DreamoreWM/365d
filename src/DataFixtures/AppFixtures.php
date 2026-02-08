<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\TypePrestation;
use App\Enum\StatutPrestation;
use App\Enum\StatutBonDeCommande;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


class AppFixtures extends Fixture
{

    private UserPasswordHasherInterface $hasher;

    public function __construct(UserPasswordHasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // --- UTILISATEURS ---
        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email);
            $user->setNom($faker->lastName);
            $hashedPassword = $this->hasher->hashPassword($user, "password");
            $user->setPassword($hashedPassword);
             $user->setRoles(['ROLE_USER']);
            $users[] = $user;
            $manager->persist($user);
        }

         $user = new User();
            $user->setEmail("admin@example.com");
            $user->setNom("alexandre");
            $hashedPassword = $this->hasher->hashPassword($user, "admin123");
            $user->setPassword($hashedPassword);
            $user->setRoles(['ROLE_ADMIN']);
            $users[] = $user;
            $manager->persist($user);

        // --- TYPES DE PRESTATION ---
        $types = [];
        for ($i = 0; $i < 5; $i++) {
            $type = new TypePrestation();
            $type->setNom($faker->word());
            $types[] = $type;
            $manager->persist($type);
        }

        // --- PRESTATIONS ---
        $prestations = [];
        for ($i = 0; $i < 40; $i++) {

            $prestation = new Prestation();
            $prestation->setTypePrestation($faker->randomElement($types));
            $prestation->setDescription($faker->sentence());

            // Génération volontaire de dates passées / actuelles / futures
            $rand = rand(1, 100);
            if ($rand <= 30) {
                // 30% dates passées
                $date = $faker->dateTimeBetween('-30 days', '-1 day');
            } elseif ($rand <= 70) {
                // 40% aujourd’hui
                $date = new \DateTime('today');
                $date->setTime(rand(8, 18), rand(0,59));
            } else {
                // 30% dates futures
                $date = $faker->dateTimeBetween('+1 day', '+30 days');
            }

            $prestation->setDatePrestation(
                \DateTimeImmutable::createFromMutable($date)
            );

            $prestation->setEmploye($faker->randomElement($users));
            $prestation->setStatut(StatutPrestation::PROGRAMME);

            $prestations[] = $prestation;
            $manager->persist($prestation);
        }

        // --- BONS DE COMMANDE ---
        for ($i = 0; $i < 10; $i++) {
            $bon = new BonDeCommande();
            $bon->setClientNom($faker->name());
            $bon->setClientAdresse($faker->address());
            $bon->setClientTelephone($faker->phoneNumber());
            $bon->setClientEmail($faker->email());
            $bon->setTypePrestation($faker->randomElement($types));

            // Date commande = avant les prestations
            $bon->setDateCommande(
                \DateTimeImmutable::createFromMutable(
                    $faker->dateTimeBetween('-40 days', 'now')
                )
            );
            $bon->setStatut(StatutBonDeCommande::A_PROGRAMMER);

            // Ajout de 1 à 3 prestations au bon
            $count = rand(1, 3);
            for ($j = 0; $j < $count; $j++) {
                $bon->addPrestation($faker->randomElement($prestations));
            }

            $manager->persist($bon);
        }

        $manager->flush();
    }
}
