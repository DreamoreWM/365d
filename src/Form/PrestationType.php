<?php

namespace App\Form;

use App\Entity\Prestation;
use App\Entity\BonDeCommande;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PrestationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('datePrestation', DateTimeType::class, [
                'label' => 'Date de prestation',
                'widget' => 'single_text',
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
            ])
            ->add('employe', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
                'label' => 'Employé assigné',
                'required' => false,
                'placeholder' => 'Aucun',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Prestation::class,
        ]);
    }
}
