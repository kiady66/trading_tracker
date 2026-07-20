<?php

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class OnboardingType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('displayName', TextType::class, [
            'label' => 'Votre pseudo',
            'help' => 'Définitif : il servira d\'adresse à votre profil public. Lettres, chiffres, tirets et underscores.',
            'attr' => ['placeholder' => 'ex. trader_du_92', 'maxlength' => 30, 'autofocus' => true],
            'constraints' => [
                new NotBlank(message: 'Choisissez un pseudo pour continuer.'),
                new Length(min: 3, max: 30, minMessage: 'Le pseudo doit faire au moins {{ limit }} caractères.', maxMessage: 'Le pseudo ne peut pas dépasser {{ limit }} caractères.'),
                new Regex(pattern: '/^[a-zA-Z0-9_-]+$/', message: 'Uniquement lettres, chiffres, tirets et underscores.'),
                new Callback($this->validateUniqueness(...)),
            ],
        ]);
    }

    /**
     * L'unicité est vérifiée insensible à la casse, comme le reste de l'application.
     * La contrainte d'unicité en base reste sensible à la casse : sans ce contrôle,
     * « Alex » et « alex » coexisteraient et donneraient deux profils publics distincts.
     */
    private function validateUniqueness(?string $displayName, ExecutionContextInterface $context): void
    {
        if ($displayName === null || $displayName === '') {
            return;
        }

        if ($this->users->findOneByDisplayNameInsensitive($displayName) !== null) {
            $context->buildViolation('Ce pseudo est déjà pris.')->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
