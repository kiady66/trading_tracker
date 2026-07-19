<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ProfileVisibilityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $builder->getData();

        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Pseudo public',
                'required' => false,
                // Figé après création : champ désactivé => Symfony ignore toute valeur soumise
                'disabled' => $user->getDisplayName() !== null,
                'attr' => ['placeholder' => 'ex. trader_du_92', 'maxlength' => 30],
                'constraints' => [
                    new Length(min: 3, max: 30, minMessage: 'Le pseudo doit faire au moins {{ limit }} caractères.', maxMessage: 'Le pseudo ne peut pas dépasser {{ limit }} caractères.'),
                    new Regex(pattern: '/^[a-zA-Z0-9_-]+$/', message: 'Uniquement lettres, chiffres, tirets et underscores.'),
                ],
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
        $resolver->setDefaults([
            'data_class' => User::class,
            'constraints' => [
                new Callback(function (User $user, ExecutionContextInterface $context): void {
                    if ($user->isShareEnabled() && $user->getDisplayName() === null) {
                        $context->buildViolation('Un pseudo est requis pour rendre votre profil visible.')
                            ->atPath('displayName')
                            ->addViolation();
                    }
                }),
            ],
        ]);
    }
}
