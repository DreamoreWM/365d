<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/api/villes')]
class VilleApiController extends AbstractController
{
    /**
     * Rechercher des villes françaises
     */
    #[Route('/search', name: 'admin_api_villes_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 2) {
            return $this->json([]);
        }
        
        // Appel à l'API geo.api.gouv.fr pour obtenir les villes françaises
        $url = "https://geo.api.gouv.fr/communes?nom=" . urlencode($query) . "&fields=nom,code,codesPostaux,centre,contour&format=json&geometry=centre";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return $this->json([]);
        }
        
        $villes = json_decode($response, true);
        
        // Formater les résultats
        $results = array_map(function($ville) {
            return [
                'nom' => $ville['nom'],
                'code' => $ville['code'],
                'codePostal' => $ville['codesPostaux'][0] ?? '',
                'latitude' => $ville['centre']['coordinates'][1] ?? null,
                'longitude' => $ville['centre']['coordinates'][0] ?? null,
            ];
        }, array_slice($villes, 0, 20)); // Limiter à 20 résultats
        
        return $this->json($results);
    }

    /**
     * Obtenir les détails complets d'une ville incluant le contour
     */
    #[Route('/details/{code}', name: 'admin_api_villes_details', methods: ['GET'])]
    public function details(string $code): JsonResponse
    {
        $url = "https://geo.api.gouv.fr/communes/{$code}?fields=nom,code,codesPostaux,centre,contour&format=json&geometry=contour";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return $this->json(['error' => 'Ville non trouvée'], 404);
        }
        
        $ville = json_decode($response, true);
        
        return $this->json([
            'nom' => $ville['nom'],
            'code' => $ville['code'],
            'codePostal' => $ville['codesPostaux'][0] ?? '',
            'latitude' => $ville['centre']['coordinates'][1] ?? null,
            'longitude' => $ville['centre']['coordinates'][0] ?? null,
            'contour' => $ville['contour'] ?? null,
        ]);
    }

    /**
     * Obtenir les villes d'un département
     */
    #[Route('/departement/{code}', name: 'admin_api_villes_departement', methods: ['GET'])]
    public function departement(string $code): JsonResponse
    {
        $url = "https://geo.api.gouv.fr/departements/{$code}/communes?fields=nom,code,codesPostaux,centre&format=json";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return $this->json([]);
        }
        
        $villes = json_decode($response, true);
        
        $results = array_map(function($ville) {
            return [
                'nom' => $ville['nom'],
                'code' => $ville['code'],
                'codePostal' => $ville['codesPostaux'][0] ?? '',
                'latitude' => $ville['centre']['coordinates'][1] ?? null,
                'longitude' => $ville['centre']['coordinates'][0] ?? null,
            ];
        }, $villes);
        
        return $this->json($results);
    }

    /**
     * Liste des départements français
     */
    #[Route('/departements', name: 'admin_api_departements', methods: ['GET'])]
    public function departements(): JsonResponse
    {
        $url = "https://geo.api.gouv.fr/departements?fields=nom,code&format=json";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (!$response) {
            return $this->json([]);
        }
        
        return $this->json(json_decode($response, true));
    }
}
