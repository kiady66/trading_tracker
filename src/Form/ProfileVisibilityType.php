<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileVisibilityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Le pseudo est choisi à l'onboarding et figé ensuite : il n'est ici
            // qu'en lecture seule. Champ désactivé => Symfony ignore toute valeur soumise.
            ->add('displayName', TextType::class, [
                'label' => 'Pseudo public',
                'required' => false,
                'disabled' => true,
                'attr' => ['maxlength' => 30],
            ])
            ->add('shareEnabled', CheckboxType::class, [
                'label' => 'Rendre mon profil visible par les autres traders',
                'required' => false,
            ])
            ->add('shareStats', CheckboxType::class, [
                'label' => 'Partager mes statistiques',
                'required' => false,
            ])
            ->add('shareOpenTrades', CheckboxType::class, [
                'label' => 'Partager mes trades en cours',
                'required' => false,
            ])
            ->add('shareClosedTrades', CheckboxType::class, [
                'label' => 'Partager mes trades terminés',
                'required' => false,
            ])
            ->add('shareCurrentMonthOnly', CheckboxType::class, [
                'label' => 'Ne montrer que le mois en cours (trades et statistiques)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Plus de contrainte « pseudo requis » : il est garanti non nul par l'onboarding.
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
