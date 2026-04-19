<?php

namespace App\Controller;

use App\Service\GeocodingService;
use App\Service\ParametreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/parametres')]
class ParametreController extends AbstractController
{
    public function __construct(
        private ParametreService $parametres,
        private GeocodingService $geocoder,
    ) {}

    #[Route('/', name: 'admin_parametre_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->render('admin/parametre/index.html.twig', [
            'parametres' => $this->parametres->listAll(),
        ]);
    }

    #[Route('/save', name: 'admin_parametre_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $previousAddress = $this->parametres->get(ParametreService::COMPANY_ADDRESS);

        foreach ($request->request->all('p') as $cle => $valeur) {
            // readonly fields (lat/lng) are written by the geocoder, not by the form
            if ($this->parametres->typeFor($cle) === 'readonly') {
                continue;
            }
            $this->parametres->set($cle, is_string($valeur) ? trim($valeur) : null);
        }

        // Re-geocode if the company address changed
        $newAddress = $this->parametres->get(ParametreService::COMPANY_ADDRESS);
        if ($newAddress && $newAddress !== $previousAddress) {
            $coords = $this->geocoder->geocode($newAddress);
            if ($coords !== null) {
                $this->parametres->set(ParametreService::COMPANY_LATITUDE,  (string) $coords['lat']);
                $this->parametres->set(ParametreService::COMPANY_LONGITUDE, (string) $coords['lng']);
                $this->addFlash('success', 'Paramètres enregistrés. Adresse société géolocalisée.');
            } else {
                $this->parametres->set(ParametreService::COMPANY_LATITUDE,  '');
                $this->parametres->set(ParametreService::COMPANY_LONGITUDE, '');
                $this->addFlash('warning', 'Paramètres enregistrés mais impossible de géolocaliser l\'adresse société.');
            }
        } else {
            $this->addFlash('success', 'Paramètres enregistrés.');
        }

        return $this->redirectToRoute('admin_parametre_index');
    }
}
