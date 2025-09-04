<?php

namespace App\Form;

use App\Entity\Trade;
use App\Entity\Timeframe;
use App\Entity\Confluence;
use App\Entity\Setup;
use App\Entity\TradeError;
use App\Entity\TradeType as EntityTradeType;
use App\Entity\Trend;
use App\Entity\Result;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class TradeTypeForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('asset', TextType::class, [
                'label' => 'Nom de l\'actif'
            ])
            ->add('entryDate', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'Date d\'entrée'
            ])
            ->add('exitDate', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'Date de sortie'
            ])
            ->add('watchlistDate', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'Date d\'ajout en watchlist'
            ])
            ->add('timeframes', EntityType::class, [
                'class' => Timeframe::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => 'name',
                'label' => 'Timeframes'
            ])
            ->add('orderType', ChoiceType::class, [
                'choices' => [
                    'Buy Market' => 'buy market',
                    'Sell Market' => 'sell market',
                    'Buy Limit' => 'buy limit',
                    'Sell Limit' => 'sell limit',
                    'Buy Stop' => 'buy stop',
                    'Sell Stop' => 'sell stop',
                ],
                'label' => 'Type d\'ordre'
            ])
            ->add('riskPercentage', NumberType::class, [
                'label' => 'Risque pris (%)',
                'scale' => 2
            ])
            ->add('result', EntityType::class, [
                'class' => Result::class,
                'required' => false,
                'choice_label' => 'name',
                'label' => 'Résultat'
            ])
            ->add('initialRR', NumberType::class, [
                'required' => false,
                'label' => 'RR initial',
                'scale' => 2
            ])
            ->add('finalRR', NumberType::class, [
                'required' => false,
                'label' => 'RR final',
                'scale' => 2
            ])
            ->add('maxRiskEuro', NumberType::class, [
                'label' => 'Risk maximal (€)',
                'scale' => 2
            ])
            ->add('tradeType', EntityType::class, [
                'class' => EntityTradeType::class,
                'required' => false,
                'choice_label' => 'name',
                'label' => 'Type de trade'
            ])
            ->add('trend', EntityType::class, [
                'class' => Trend::class,
                'required' => false,
                'choice_label' => 'name',
                'label' => 'Tendance'
            ])
            ->add('tradeManagement', CheckboxType::class, [
                'required' => false,
                'label' => 'Trade management'
            ])
            ->add('error', EntityType::class, [
                'class' => TradeError::class,
                'required' => false,
                'choice_label' => 'name',
                'label' => 'Erreur'
            ])
            ->add('goodTrade', CheckboxType::class, [
                'required' => false,
                'label' => 'Bon trade'
            ])
            ->add('confluences', EntityType::class, [
                'class' => Confluence::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => 'name',
                'label' => 'Confluences'
            ])
            ->add('setups', EntityType::class, [
                'class' => Setup::class,
                'multiple' => true,
                'expanded' => true,
                'choice_label' => 'name',
                'label' => 'Setups'
            ])->add('executionReason', TextareaType::class, [
                'required' => false,
                'label' => 'Raison d\'exécution',
                'attr' => ['rows' => 4]
            ])
            ->add('noteErrors', TextareaType::class, [
                'required' => false,
                'label' => 'Notes sur les erreurs',
                'attr' => ['rows' => 4]
            ])
            ->add('executionScreenshots', FileType::class, [
                'required' => false,
                'multiple' => true,
                'mapped' => false,
                'label' => 'Screenshots d\'exécution'
            ])->add('managementScreenshots', FileType::class, [
                'required' => false,
                'multiple' => true,
                'mapped' => false,
                'label' => 'Screenshots de management'
            ])
            ->add('closingScreenshots', FileType::class, [
                'required' => false,
                'multiple' => true,
                'mapped' => false,
                'label' => 'Screenshots de clôture'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Trade::class,
        ]);
    }
}
