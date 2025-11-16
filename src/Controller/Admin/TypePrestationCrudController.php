<?php

namespace App\Controller\Admin;

use App\Entity\TypePrestation;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class TypePrestationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TypePrestation::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('nom', 'Nom du type de prestation'),
            IntegerField::new('nombrePrestationsNecessaires', 'Nombre de prestations nécessaires'),
        ];
    }
}
