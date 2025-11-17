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
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;


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



    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL); // 🔥 ajoute le bouton "Voir" dans la liste
    }


    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prestation')
            ->setEntityLabelInPlural('Prestations')
            ->setDefaultSort(['datePrestation' => 'DESC'])
            ->showEntityActionsInlined()
            ->overrideTemplates([
                'crud/detail' => 'admin/prestation_detail.html.twig',
            ]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('datePrestation')
            ->add('employe')
            ->add('description');
    }




    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()
        ->setSortable(false);

        yield DateTimeField::new('datePrestation')
            ->setFormTypeOptions([
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'min' => (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i'),
                ],
            ])
            ->setSortable(true)
            ->setCustomOption('sortable', 'datePrestation')
            ->setSortable(false);

        yield TextField::new('description', 'Description')
        ->setSortable(false);

        yield AssociationField::new('bonDeCommande', 'Bon de commande')
        ->setSortable(false);

        yield AssociationField::new('employe', 'Employé assigné')
            ->setRequired(false)
            ->setFormTypeOptions(['choice_label' => 'email'])
            ->setSortable(false);

        // 🔥 Le champ preview PDF, uniquement pour la page "detail"
        yield TextField::new('pdfPreview', 'Aperçu PDF')
            ->onlyOnDetail()
            ->renderAsHtml()
            ->formatValue(function ($value, $entity) {
                return sprintf(
                    '<iframe src="/prestation/%d/pdf" style="width:100%%; height:800px; border:1px solid #ddd; border-radius:8px;"></iframe>',
                    $entity->getId()
                );
            })
            ->setSortable(false);
    }


    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->prestationManager->updatePrestationStatut($entityInstance);

        parent::persistEntity($em, $entityInstance);

        $this->prestationManager->updateBonDeCommande($entityInstance->getBonDeCommande());

        $bon = $entityInstance->getBonDeCommande();
        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->prestationManager->updatePrestationStatut($entityInstance);

        parent::updateEntity($em, $entityInstance);

        $this->prestationManager->updateBonDeCommande($entityInstance->getBonDeCommande());

        $bon = $entityInstance->getBonDeCommande();
        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }
    }

    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $bon = $entityInstance->getBonDeCommande();

        parent::deleteEntity($em, $entityInstance);

        if ($bon) {
            $this->prestationManager->updateBonDeCommande($bon);
        }
    }

    #[Route('/admin/prestation/detail/{id}', name: 'prestation_detail_modal')]
    public function modalDetail(Prestation $prestation): Response
    {
        return $this->render('admin/prestation_detail_modal.html.twig', [
            'prestation' => $prestation
        ]);
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
