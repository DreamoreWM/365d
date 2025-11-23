<?php

namespace App\Controller;

use App\Entity\GroupeGeographique;
use App\Repository\GroupeGeographiqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/groupes-geographiques')]
class GroupeGeographiqueController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private GroupeGeographiqueRepository $groupeRepo
    ) {}

    /**
     * Page principale avec la carte interactive
     */
    #[Route('', name: 'admin_groupe_geo_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/groupe_geographique/index.html.twig', [
            'groupes' => $this->groupeRepo->findAllActifs(),
        ]);
    }

    /**
     * Récupérer tous les groupes (API)
     */
    #[Route('/api/groupes', name: 'admin_groupe_geo_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $groupes = $this->groupeRepo->findAllActifs();
        
        $data = array_map(function(GroupeGeographique $groupe) {
            return [
                'id' => $groupe->getId(),
                'nom' => $groupe->getNom(),
                'description' => $groupe->getDescription(),
                'couleur' => $groupe->getCouleur(),
                'villes' => $groupe->getVilles(),
                'villesData' => $groupe->getVillesData(),
                'actif' => $groupe->isActif(),
            ];
        }, $groupes);
        
        return $this->json($data);
    }

    /**
     * Créer un nouveau groupe
     */
    #[Route('/api/groupes', name: 'admin_groupe_geo_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['nom']) || empty($data['nom'])) {
            return $this->json(['error' => 'Le nom est requis'], 400);
        }
        
        $groupe = new GroupeGeographique();
        $groupe->setNom($data['nom']);
        $groupe->setDescription($data['description'] ?? null);
        $groupe->setVilles($data['villes'] ?? []);
        $groupe->setVillesData($data['villesData'] ?? []);
        
        if (isset($data['couleur'])) {
            $groupe->setCouleur($data['couleur']);
        }
        
        $this->em->persist($groupe);
        $this->em->flush();
        
        return $this->json([
            'id' => $groupe->getId(),
            'nom' => $groupe->getNom(),
            'description' => $groupe->getDescription(),
            'couleur' => $groupe->getCouleur(),
            'villes' => $groupe->getVilles(),
            'villesData' => $groupe->getVillesData(),
            'actif' => $groupe->isActif(),
        ], 201);
    }

    /**
     * Mettre à jour un groupe
     */
    #[Route('/api/groupes/{id}', name: 'admin_groupe_geo_update', methods: ['PUT'])]
    public function update(GroupeGeographique $groupe, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (isset($data['nom'])) {
            $groupe->setNom($data['nom']);
        }
        
        if (isset($data['description'])) {
            $groupe->setDescription($data['description']);
        }
        
        if (isset($data['couleur'])) {
            $groupe->setCouleur($data['couleur']);
        }
        
        if (isset($data['villes'])) {
            $groupe->setVilles($data['villes']);
        }
        
        if (isset($data['villesData'])) {
            $groupe->setVillesData($data['villesData']);
        }
        
        if (isset($data['actif'])) {
            $groupe->setActif($data['actif']);
        }
        
        $this->em->flush();
        
        return $this->json([
            'id' => $groupe->getId(),
            'nom' => $groupe->getNom(),
            'description' => $groupe->getDescription(),
            'couleur' => $groupe->getCouleur(),
            'villes' => $groupe->getVilles(),
            'villesData' => $groupe->getVillesData(),
            'actif' => $groupe->isActif(),
        ]);
    }

    /**
     * Supprimer un groupe (soft delete)
     */
    #[Route('/api/groupes/{id}', name: 'admin_groupe_geo_delete', methods: ['DELETE'])]
    public function delete(GroupeGeographique $groupe): JsonResponse
    {
        $groupe->setActif(false);
        $this->em->flush();
        
        return $this->json(['success' => true]);
    }

    /**
     * Supprimer définitivement un groupe
     */
    #[Route('/api/groupes/{id}/hard-delete', name: 'admin_groupe_geo_hard_delete', methods: ['DELETE'])]
    public function hardDelete(GroupeGeographique $groupe): JsonResponse
    {
        $this->em->remove($groupe);
        $this->em->flush();
        
        return $this->json(['success' => true]);
    }

    /**
     * Ajouter une ville à un groupe
     */
    #[Route('/api/groupes/{id}/villes', name: 'admin_groupe_geo_add_ville', methods: ['POST'])]
    public function addVille(GroupeGeographique $groupe, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['ville'])) {
            return $this->json(['error' => 'La ville est requise'], 400);
        }
        
        $groupe->addVille($data['ville']);
        $this->em->flush();
        
        return $this->json([
            'villes' => $groupe->getVilles(),
        ]);
    }

    /**
     * Retirer une ville d'un groupe
     */
    #[Route('/api/groupes/{id}/villes', name: 'admin_groupe_geo_remove_ville', methods: ['DELETE'])]
    public function removeVille(GroupeGeographique $groupe, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['ville'])) {
            return $this->json(['error' => 'La ville est requise'], 400);
        }
        
        $groupe->removeVille($data['ville']);
        $this->em->flush();
        
        return $this->json([
            'villes' => $groupe->getVilles(),
        ]);
    }
}