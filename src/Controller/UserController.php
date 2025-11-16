<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserController extends AbstractController
{
    #[Route('/profil', name: 'app_user_profil')]
    public function profil(Request $request, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $user->setEmail($request->request->get('email'));
            $user->setNom($request->request->get('nom'));
            $user->setTelephone($request->request->get('telephone'));

            $signature = $request->request->get('signature');
            if ($signature) {
                $user->setSignature($signature);
            }

            $em->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès !');
        }

        return $this->render('dashboard/user/profil.html.twig', [
            'user' => $user
        ]);
    }
}
