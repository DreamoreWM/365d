<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\BonDeCommande;
use App\Entity\Prestation;
use App\Entity\TypePrestation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Redirection automatique vers le CRUD des bons de commande
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect($adminUrlGenerator->setController(BonDeCommandeCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Gestion 4D')
            ->renderContentMaximized()
            ->setFaviconPath('favicon.ico');
    }

 public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');

        yield MenuItem::section('🧾 Bons de commande');

        yield MenuItem::linkToCrud('Tous les bons', 'fa fa-list', BonDeCommande::class);
        yield MenuItem::linkToCrud('À programmer', 'fa fa-calendar', BonDeCommande::class)
            ->setController(BonDeCommandeCrudController::class)
            ->setQueryParameter('statut', 'à programmer');

        yield MenuItem::linkToCrud('Programmé', 'fa fa-clock', BonDeCommande::class)
            ->setController(BonDeCommandeCrudController::class)
            ->setQueryParameter('statut', 'programmé');

        yield MenuItem::linkToCrud('En cours', 'fa fa-play', BonDeCommande::class)
            ->setController(BonDeCommandeCrudController::class)
            ->setQueryParameter('statut', 'en cours');

        yield MenuItem::linkToCrud('Terminé', 'fa fa-check', BonDeCommande::class)
            ->setController(BonDeCommandeCrudController::class)
            ->setQueryParameter('statut', 'terminé');

        yield MenuItem::section('🧰 Prestations');

        yield MenuItem::linkToCrud('Toutes les prestations', 'fa fa-list', Prestation::class);
        yield MenuItem::linkToCrud('À programmer', 'fa fa-calendar', Prestation::class)
            ->setController(PrestationCrudController::class)
            ->setQueryParameter('statut', 'à programmer');

        yield MenuItem::linkToCrud('Programmées', 'fa fa-clock', Prestation::class)
            ->setController(PrestationCrudController::class)
            ->setQueryParameter('statut', 'programmé');

        yield MenuItem::linkToCrud('En cours', 'fa fa-play', Prestation::class)
            ->setController(PrestationCrudController::class)
            ->setQueryParameter('statut', 'en cours');

        yield MenuItem::linkToCrud('Terminées', 'fa fa-check', Prestation::class)
            ->setController(PrestationCrudController::class)
            ->setQueryParameter('statut', 'terminé');
    }
}
