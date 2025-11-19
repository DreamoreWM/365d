<?php

namespace App\Controller;

use App\Entity\TypePrestation;
use App\Repository\TypePrestationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/type-prestation')]
class TypePrestationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TypePrestationRepository $repository
    ) {}

    // =====================================================
    // LISTE DES TYPES DE PRESTATIONS
    // =====================================================
    #[Route('/', name: 'admin_type_prestation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('search', '');

        $qb = $this->repository->createQueryBuilder('t')
            ->orderBy('t.nom', 'ASC');

        if ($search) {
            $qb->andWhere('t.nom LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $types = $qb->getQuery()->getResult();

        return $this->render('admin/type_prestation/index.html.twig', [
            'types' => $types,
        ]);
    }

    // =====================================================
    // CRÉER UN NOUVEAU TYPE
    // =====================================================
    #[Route('/new', name: 'admin_type_prestation_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $type = new TypePrestation();

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $type, true);
        }

        return $this->render('admin/type_prestation/form.html.twig', [
            'type' => $type,
            'isNew' => true,
        ]);
    }

    // =====================================================
    // VOIR UN TYPE
    // =====================================================
    #[Route('/{id}', name: 'admin_type_prestation_show', methods: ['GET'])]
    public function show(TypePrestation $type): Response
    {
        return $this->render('admin/type_prestation/show.html.twig', [
            'type' => $type,
        ]);
    }

    // =====================================================
    // MODIFIER UN TYPE
    // =====================================================
    #[Route('/{id}/edit', name: 'admin_type_prestation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, TypePrestation $type): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $type, false);
        }

        return $this->render('admin/type_prestation/form.html.twig', [
            'type' => $type,
            'isNew' => false,
        ]);
    }

    // =====================================================
    // SUPPRIMER UN TYPE
    // =====================================================
    #[Route('/{id}/delete', name: 'admin_type_prestation_delete', methods: ['POST'])]
    public function delete(Request $request, TypePrestation $type): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_type_prestation_index');
        }

        // Vérifier si le type est utilisé
        if ($type->getPrestations()->count() > 0) {
            $this->addFlash('danger', 'Impossible de supprimer ce type car il est utilisé par des prestations');
            return $this->redirectToRoute('admin_type_prestation_index');
        }

        $nom = $type->getNom();

        $this->em->remove($type);
        $this->em->flush();

        $this->addFlash('success', "Le type de prestation \"{$nom}\" a été supprimé");

        return $this->redirectToRoute('admin_type_prestation_index');
    }

    // =====================================================
    // MÉTHODE PRIVÉE : TRAITEMENT DU FORMULAIRE
    // =====================================================
    private function handleForm(Request $request, TypePrestation $type, bool $isNew): Response
    {
        $type->setNom($request->request->get('nom'));
        $type->setNombrePrestationsNecessaires((int) $request->request->get('nombrePrestationsNecessaires', 1));

        // Validation basique
        if (!$type->getNom()) {
            $this->addFlash('danger', 'Le nom est obligatoire');
            return $this->redirectToRoute($isNew ? 'admin_type_prestation_new' : 'admin_type_prestation_edit', 
                $isNew ? [] : ['id' => $type->getId()]
            );
        }

        if ($type->getNombrePrestationsNecessaires() < 1) {
            $this->addFlash('danger', 'Le nombre de prestations doit être au moins 1');
            return $this->redirectToRoute($isNew ? 'admin_type_prestation_new' : 'admin_type_prestation_edit', 
                $isNew ? [] : ['id' => $type->getId()]
            );
        }

        $this->em->persist($type);
        $this->em->flush();

        $this->addFlash('success', $isNew 
            ? 'Type de prestation créé avec succès' 
            : 'Type de prestation modifié avec succès'
        );

        return $this->redirectToRoute('admin_type_prestation_show', ['id' => $type->getId()]);
    }
}