<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestTailwindController extends AbstractController
{
    #[Route('/test_tailwind', name: 'test_tailwind')]
    public function index(): Response
    {
        return $this->render('test_tailwind.html.twig');
    }
}
