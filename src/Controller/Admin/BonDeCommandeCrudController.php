<?php

namespace App\Controller\Admin;

use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use thiagoalessio\TesseractOCR\TesseractOCR;

class BonDeCommandeCrudController extends AbstractCrudController
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function getEntityFqcn(): string
    {
        return BonDeCommande::class;
    }

    
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->overrideTemplates([
            'crud/edit' => 'admin/bon/edit_bdc.html.twig',
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('numeroCommande', 'Numéro Commande'),
            TextField::new('clientNom', 'Nom du client'),
            TextField::new('clientAdresse', 'Adresse'),
            TextField::new('clientComplementAdresse', 'Complément d’adresse')->onlyOnForms(),
            TextField::new('clientTelephone', 'Téléphone'),
            AssociationField::new('typePrestation', 'Type de prestation'),

            NumberField::new('nombrePrestationsNecessaires', 'Prestations nécessaires')
                ->onlyOnIndex(),
            NumberField::new('nombrePrestations', 'Prestations effectuées')
                ->onlyOnIndex(),

            ChoiceField::new('statut', 'Statut')
                ->setDisabled(true)
                ->setChoices([
                    'À programmer' => 'à programmer',
                    'Programmé' => 'programmé',
                    'En cours' => 'en cours',
                    'Non effectué' => 'non effectué',
                    'Terminé' => 'terminé',
                ])
                ->renderAsBadges([
                    'à programmer' => 'warning',
                    'programmé' => 'info',
                    'en cours' => 'primary',
                    'non effectué' => 'danger',
                    'terminé' => 'success',
                ]),
        ];
    }

    // -----------------------------------------------------
    // 🚀 AJOUT DU BOUTON "Importer via OCR"
    // -----------------------------------------------------
    public function configureActions(Actions $actions): Actions
    {
        $importAction = Action::new('import_ocr', '📸 Importer via OCR')
            ->setIcon('fa fa-camera')
            ->setLabel('📸 Importer via OCR')
            ->linkToCrudAction('importViaOcr')
            ->createAsGlobalAction(); // ✅ bouton global sur la page index

        return $actions
            ->add(Crud::PAGE_INDEX, $importAction)
            // exemple de mise à jour d'une action existante :
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setLabel('➕ Nouveau bon');
            });
    }



    // -----------------------------------------------------
    // 📸 PAGE D’IMPORTATION OCR
    // -----------------------------------------------------
    public function importViaOcr(Request $request): Response
    {
        if ($request->isMethod('POST') && $file = $request->files->get('photo')) {
            $tmpPath = sys_get_temp_dir() . '/' . uniqid('ocr_') . '.' . $file->guessExtension();
            $file->move(sys_get_temp_dir(), basename($tmpPath));

            $ocr = new TesseractOCR($tmpPath);
            $text = $ocr->lang('fra', 'eng')->psm(3)->run();

            unlink($tmpPath);

            // ---- Extraction basique ----
            preg_match('/Commande\s*(?:n[\s°º]*)?[:\-\s]*([A-Z0-9]+)/i', $text, $mNumero);
            preg_match('/éditée[, ]+le\s*([0-9\/]+)/i', $text, $mDate);

            $start = stripos($text, 'Travaux à réaliser');
            if ($start !== false) {
                $text = substr($text, $start);
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $indexPrestation = null;
            foreach ($lines as $i => $line) {
                if (stripos($line, 'Prestation Parties Privatives') !== false) {
                    $indexPrestation = $i;
                    break;
                }
            }

            $complement = '';
            $adresse = '';
            $nomClient = '';
            $telephone = '';

            if ($indexPrestation !== null) {
                $complement = $lines[$indexPrestation + 1] ?? '';
                $adresseLigne1 = $lines[$indexPrestation + 2] ?? '';
                $adresseLigne2 = $lines[$indexPrestation + 3] ?? '';
                $adresse = trim($adresseLigne1 . "\n" . $adresseLigne2);

                foreach ($lines as $line) {
                    if (stripos($line, 'Logement Occupé') !== false) {
                        if (preg_match('/Logement\s+Occupé\s*:\s*(.*?)\s*-\s*Portable\s*:\s*([0-9]+)/i', $line, $m)) {
                            $nomClient = trim($m[1]);
                            $telephone = trim($m[2]);
                        } elseif (preg_match('/Logement\s+Occupé\s*:\s*(.*)/i', $line, $m)) {
                            $nomClient = trim($m[1]);
                        }
                        break;
                    }
                }
            }

            // 🔁 Redirection vers formulaire "nouveau bon"
            return $this->redirect($this->generateUrl('admin', [
                'crudControllerFqcn' => self::class,
                'crudAction' => 'new',
                'numeroCommande' => $mNumero[1] ?? '',
                'clientNom' => $nomClient,
                'clientAdresse' => $adresse,
                'clientTelephone' => $telephone,
                'clientComplementAdresse' => $complement,
            ]));
        }

        // ---- Affiche le mini formulaire ----
        return $this->render('admin/import_ocr.html.twig', [
            'backUrl' => $this->generateUrl('admin', [
                'crudControllerFqcn' => self::class,
                'crudAction' => 'index'
            ])
        ]);
    }

    // -----------------------------------------------------
    // 🧱 PRÉREMPLISSAGE DU FORMULAIRE NEW
    // -----------------------------------------------------
    public function createEntity(string $entityFqcn)
    {
        $bon = new BonDeCommande();

        $request = $this->getContext()?->getRequest();
        if ($request) {
            $bon->setNumeroCommande($request->query->get('numeroCommande', ''));
            $bon->setClientNom($request->query->get('clientNom', ''));
            $bon->setClientAdresse($request->query->get('clientAdresse', ''));
            $bon->setClientTelephone($request->query->get('clientTelephone', ''));
            $bon->setClientComplementAdresse($request->query->get('clientComplementAdresse', ''));
            $bon->setClientEmail($request->query->get('',''));
            $bon->setStatut('à programmer');
        }

        return $bon;
    }

    // -----------------------------------------------------
    // ⚙️ MÉCANISME DE MISE À JOUR DES PRESTATIONS
    // -----------------------------------------------------
    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->updateNombrePrestationsNecessaires($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->updateNombrePrestationsNecessaires($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    private function updateNombrePrestationsNecessaires(BonDeCommande $bon): void
    {
        if ($type = $bon->getTypePrestation()) {
            $bon->setNombrePrestationsNecessaires($type->getNombrePrestationsNecessaires());
        } else {
            $bon->setNombrePrestationsNecessaires(0);
        }

        $this->em->persist($bon);
        $this->em->flush();
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
