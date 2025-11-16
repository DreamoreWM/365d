<?php

namespace App\Controller\Admin;

use App\Entity\BonDeCommande;
use App\Entity\Prestation;
use App\Form\PrestationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\PrestationManager;

class PrestationModalController extends AbstractController
{

    public function __construct(private PrestationManager $prestationManager)
    {
    }

    #[Route('/admin/prestation/modal/new/{bon}', name: 'ea_prestation_modal_new')]
    public function modalNew(BonDeCommande $bon, Request $request)
    {
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);

        $form = $this->createForm(PrestationType::class, $prestation);

        return $this->render('admin/prestation/modal_form.html.twig', [
            'form' => $form->createView(),
            'bon'  => $bon->getId(),
        ]);

    }

    private function updatePrestationStatut(Prestation $prestation): void
    {
        $now = new \DateTimeImmutable('today');
        $date = $prestation->getDatePrestation();

        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            $prestation->setStatut('en cours');
        } elseif ($date > $now) {
            $prestation->setStatut('programmé');
        } else {
            $prestation->setStatut('terminé');
        }
    }


    #[Route('/admin/prestation/modal/save/{bon}', name: 'ea_prestation_modal_save', methods: ['POST'])]
    public function modalSave(BonDeCommande $bon, Request $request, EntityManagerInterface $em)
    {
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);

        $form = $this->createForm(PrestationType::class, $prestation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 1️⃣ Déterminer statut de la prestation AVANT save
            $this->prestationManager->updatePrestationStatut($prestation);

            // 2️⃣ Persister la prestation
            $em->persist($prestation);
            $em->flush();

            // 3️⃣ Mettre à jour le Bon de Commande
            $this->prestationManager->updateBonDeCommande($bon);

            return $this->redirect($this->generateUrl('admin', [
                'crudControllerFqcn' => \App\Controller\Admin\BonDeCommandeCrudController::class,
                'crudAction' => 'edit',
                'entityId' => $bon->getId(),
            ]));
        }

        return $this->render('admin/prestation/modal_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }



}
