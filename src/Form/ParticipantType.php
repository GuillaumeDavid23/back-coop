<?php

namespace App\Form;

use App\Entity\Participant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Édition d'un participant embarquée dans le formulaire d'édition d'une
 * inscription (voir RegistrationCrudController) - volontairement limitée
 * aux informations personnelles : le tarif/forfait et le statut de
 * l'inscription ne sont jamais éditables ici (voir RegistrationCrudController).
 *
 * Les choix de civility/status reprennent exactement ceux du formulaire
 * d'inscription public (voir App\Site\SeminaireCAC\Form\RegistrationStep1Type
 * et Service\Export\AnswerHumanizer, qui humanisent les mêmes codes).
 */
final class ParticipantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('civility', ChoiceType::class, [
                'choices' => ['Madame' => 'mme', 'Monsieur' => 'm'],
                'placeholder' => '-',
                'required' => false,
                'label' => 'Civilité',
            ])
            ->add('firstName', TextType::class, ['label' => 'Prénom'])
            ->add('lastName', TextType::class, ['label' => 'Nom'])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('phone', TextType::class, ['required' => false, 'label' => 'Téléphone'])
            ->add('company', TextType::class, ['required' => false, 'label' => 'Société / Cabinet'])
            ->add('status', ChoiceType::class, [
                'choices' => ['Salarié' => 'salarie', 'Travailleur non salarié' => 'tns'],
                'placeholder' => '-',
                'required' => false,
                'label' => 'Statut',
            ])
            ->add('address', TextType::class, ['required' => false, 'label' => 'Adresse'])
            ->add('postalCode', TextType::class, ['required' => false, 'label' => 'Code postal'])
            ->add('city', TextType::class, ['required' => false, 'label' => 'Ville'])
            ->add('motivation', TextareaType::class, ['required' => false, 'label' => 'Motif'])
            ->add('specialNeeds', TextareaType::class, ['required' => false, 'label' => 'Besoins spécifiques'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Participant::class]);
    }
}
