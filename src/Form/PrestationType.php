<?php

namespace App\Form;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PrestationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('datePrestation', DateTimeType::class, [
                'widget' => 'single_text',
                'label'  => 'Date de prestation',
                'attr'   => ['class' => 'form-control'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('bonDeCommande', EntityType::class, [
                'class' => BonDeCommande::class,
                'label' => 'Bon de commande',
                'choice_label' => function ($bon) {
                    return sprintf(
                        '#%s — %s — %s',
                        $bon->getId(),
                        $bon->getClientNom(),
                        $bon->getClientAdresse()
                    );
                },
                'disabled' => true,   // 🔒 lecture seule
                'attr' => ['class' => 'form-select'],
            ])
            ->add('employe', EntityType::class, [
                'class' => User::class,
                'label' => 'Employé assigné',
                'required' => false,
                'choice_label' => 'email', // comme ton EasyAdmin
                'placeholder' => 'Aucun',
                'attr' => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prestation::class,
        ]);
    }
}
