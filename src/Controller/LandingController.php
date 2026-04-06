<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class LandingController extends AbstractController
{
    #[Route('/landing', name: 'app_landing', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('landing/index.html.twig');
    }
}
