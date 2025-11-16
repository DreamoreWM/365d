<?php

namespace App\Controller\Admin;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;    
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto; 
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use App\Service\PrestationManager;
use Symfony\Component\Routing\Annotation\Route;
use App\Form\PrestationType;

class PrestationCrudController extends AbstractCrudController
{
    public function __construct(
        private EntityManagerInterface $em, 
        private PrestationManager $prestationManager
    ) {}

    public static function getEntityFqcn(): string
    {
        return Prestation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prestation')
            ->setEntityLabelInPlural('Prestations')
            ->setDefaultSort(['datePrestation' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            DateTimeField::new('datePrestation')
                ->setFormTypeOptions([
                    'widget' => 'single_text',
                    'html5' => true,
                    'attr' => [
                        'min' => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
                    ],
                ]),

            TextField::new('description', 'Description'),

            AssociationField::new('bonDeCommande', 'Bon de commande'),

            AssociationField::new('employe', 'Employé assigné')
                ->setRequired(false)
                ->setFormTypeOptions([
                    'choice_label' => 'email'
                ]),
        ];
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->prestationManager->updatePrestationStatut($entityInstance);

        parent::persistEntity($em, $entityInstance);

        $this->prestationManager->updateBonDeCommande($entityInstance->getBonDeCommande());
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->prestationManager->updatePrestationStatut($entityInstance);

        parent::updateEntity($em, $entityInstance);

        $this->prestationManager->updateBonDeCommande($entityInstance->getBonDeCommande());
    }

    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $bon = $entityInstance->getBonDeCommande();

        parent::deleteEntity($em, $entityInstance);

        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }
    }

    #[Route('/admin/prestation/modal/new/{bon}', name: 'ea_prestation_modal_new')]
    public function modalNew(BonDeCommande $bon)
    {
        $prestation = new Prestation();
        $prestation->setBonDeCommande($bon);

        $form = $this->createForm(PrestationType::class, $prestation);

        return $this->render('admin/prestation/modal_form.html.twig', [
            'form' => $form->createView(),
            'bon'  => $bon->getId(), // 🔥 on renvoie bien la variable que la modal attend
        ]);
    }

        public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $request = $this->getContext()?->getRequest();
        $statut = $request?->query->get('statut');

        if ($statut) {
            $qb->andWhere('entity.statut = :statut')
               ->setParameter('statut', $statut);
        }

        return $qb;
    }
}
