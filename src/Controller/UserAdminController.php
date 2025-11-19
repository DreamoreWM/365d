<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/admin/user')]
class UserAdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $repository,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    // =====================================================
    // LISTE DES UTILISATEURS
    // =====================================================
    #[Route('/', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('search', '');
        $role = $request->query->get('role', '');

        $qb = $this->repository->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC');

        // Filtre de recherche
        if ($search) {
            $qb->andWhere('u.nom LIKE :search OR u.email LIKE :search OR u.telephone LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filtre par rôle
        if ($role) {
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%' . $role . '%');
        }

        $users = $qb->getQuery()->getResult();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    // =====================================================
    // CRÉER UN NOUVEL UTILISATEUR
    // =====================================================
    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();

        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $user, true);
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'isNew' => true,
        ]);
    }

    // =====================================================
    // VOIR UN UTILISATEUR
    // =====================================================
    #[Route('/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    // =====================================================
    // MODIFIER UN UTILISATEUR
    // =====================================================
    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleForm($request, $user, false);
        }

        return $this->render('admin/user/form.html.twig', [
            'user' => $user,
            'isNew' => false,
        ]);
    }

    // =====================================================
    // SUPPRIMER UN UTILISATEUR
    // =====================================================
    #[Route('/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('admin_user_index');
        }

        // Empêcher la suppression de soi-même
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('danger', 'Vous ne pouvez pas supprimer votre propre compte');
            return $this->redirectToRoute('admin_user_index');
        }

        $nom = $user->getNom();

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "L'utilisateur {$nom} a été supprimé");

        return $this->redirectToRoute('admin_user_index');
    }

    // =====================================================
    // MÉTHODE PRIVÉE : TRAITEMENT DU FORMULAIRE
    // =====================================================
    private function handleForm(Request $request, User $user, bool $isNew): Response
    {
        $user->setNom($request->request->get('nom'));
        $user->setEmail($request->request->get('email'));
        $user->setTelephone($request->request->get('telephone'));

        // Gestion des rôles
        $roles = $request->request->all('roles') ?? [];
        if (empty($roles)) {
            $roles = ['ROLE_USER'];
        }
        $user->setRoles($roles);

        // Gestion du mot de passe
        $plainPassword = $request->request->get('password');
        if ($plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        } elseif ($isNew) {
            $this->addFlash('danger', 'Le mot de passe est obligatoire pour un nouvel utilisateur');
            return $this->redirectToRoute('admin_user_new');
        }

        // Validation basique
        if (!$user->getNom() || !$user->getEmail()) {
            $this->addFlash('danger', 'Le nom et l\'email sont obligatoires');
            return $this->redirectToRoute($isNew ? 'admin_user_new' : 'admin_user_edit', 
                $isNew ? [] : ['id' => $user->getId()]
            );
        }

        // Vérification email unique
        if ($isNew) {
            $existant = $this->repository->findOneBy(['email' => $user->getEmail()]);
            if ($existant) {
                $this->addFlash('danger', 'Cet email est déjà utilisé');
                return $this->redirectToRoute('admin_user_new');
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->addFlash('success', $isNew 
            ? 'Utilisateur créé avec succès' 
            : 'Utilisateur modifié avec succès'
        );

        return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
    }
}