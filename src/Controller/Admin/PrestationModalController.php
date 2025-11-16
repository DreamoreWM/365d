<?php

namespace App\Controller\Admin;

use App\Entity\BonDeCommande;
use App\Entity\Prestation;
use App\Form\PrestationType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;


class PrestationModalController extends AbstractController
{
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

    #[Route('/admin/prestation/modal/save/{bon}', name: 'ea_prestation_modal_save', methods: ['POST'])]
    public function modalSave(BonDeCommande $bon, Request $request, EntityManagerInterface $em)
    {
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);

        $form = $this->createForm(PrestationType::class, $prestation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($prestation);
            $em->flush();

            // Retour à la page du bon de commande
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
