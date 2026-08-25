<?php

namespace App\Site\SeminaireCAC\Form;

use App\Site\SeminaireCAC\Service\FareCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationStep1Type extends AbstractType
{
    /**
     * Empêche les gestionnaires de mots de passe (Bitwarden, 1Password,
     * LastPass...) d'injecter leur icône dans ces champs — ils n'ont rien
     * à y suggérer, et ça casse le rendu du formulaire par rapport à la
     * maquette.
     */
    private const array NO_AUTOFILL_ATTR = [
        'autocomplete' => 'off',
        'data-lpignore' => 'true',
        'data-1p-ignore' => 'true',
        'data-bwignore' => 'true',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $validFareCodes = array_keys(FareCatalog::all());

        $builder
            ->add('fare', HiddenType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: $validFareCodes)],
            ])
            ->add('civility', ChoiceType::class, [
                'choices' => ['Madame' => 'mme', 'Monsieur' => 'm'],
                'placeholder' => '—',
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Civilité',
            ])
            ->add('lastName', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
                'label' => 'Nom',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('firstName', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
                'label' => 'Prénom',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('mobile', TelType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Mobile',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('email', EmailType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
                'label' => 'Email',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('company', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 190)],
                'label' => 'Cabinet',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('address', TextType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Adresse',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('postalCode', TextType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Code postal',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('city', TextType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Ville',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('status', ChoiceType::class, [
                'choices' => ['Salarié' => 'salarie', 'Travailleur non salarié' => 'tns'],
                'placeholder' => '—',
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Statut',
            ])
            ->add('motivation', TextareaType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => "Dans une démarche qualité et afin de répondre au mieux à votre demande, merci de nous indiquer le motif de votre inscription et vos attentes",
            ])
            ->add('specialNeeds', TextareaType::class, [
                'required' => false,
                'label' => 'Avez-vous des besoins spécifiques pour suivre la formation ?',
                'attr' => ['placeholder' => 'Accessibilité, régime alimentaire, aménagement particulier…'],
            ])
            ->add('cocktailAttendance', ChoiceType::class, [
                'choices' => ['Oui' => 'oui', 'Non' => 'non'],
                'placeholder' => '—',
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Je confirme ma présence au cocktail apéritif du mardi à l\'issue de la formation',
            ])
            ->add('consentAccepted', CheckboxType::class, [
                'constraints' => [new Assert\IsTrue()],
                'label' => "J'accepte les conditions générales de vente",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
