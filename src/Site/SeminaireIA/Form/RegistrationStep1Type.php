<?php

namespace App\Site\SeminaireIA\Form;

use App\Site\SeminaireIA\Service\FareCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Étape 1 du Séminaire IA. Trois validations conditionnelles, pilotées par des
 * groupes dynamiques (voir configureOptions) :
 *  - "cooperateur" : le numéro de facture d'adhésion (FA1234567) devient
 *    obligatoire quand Statut = Coopérateur ;
 *  - "two_person"  : le bloc participant 2 et le type de chambre deviennent
 *    obligatoires pour les forfaits à deux personnes.
 * L'affichage conditionnel côté navigateur est géré dans step1.html.twig - la
 * validation serveur reste la seule source de vérité.
 */
final class RegistrationStep1Type extends AbstractType
{
    /**
     * Empêche les gestionnaires de mots de passe (Bitwarden, 1Password,
     * LastPass...) d'injecter leur icône dans ces champs - ils n'ont rien
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
        $statusChoices = array_flip(FareCatalog::statuses());
        $fareChoices = array_combine(
            array_column(FareCatalog::all(), 'label'),
            array_keys(FareCatalog::all()),
        );

        $builder
            // Le statut est une liste déroulante (demande de la cliente) ; le
            // forfait reste en "cartes" cliquables (voir step1.html.twig), donc
            // expanded pour disposer de vrais boutons radio.
            ->add('statut', ChoiceType::class, [
                'choices' => $statusChoices,
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Statut',
            ])
            ->add('subscriptionNumber', TextType::class, [
                'required' => false,
                'label' => "Numéro de facture d'adhésion",
                'help' => 'Format attendu : FA1234567',
                'attr' => self::NO_AUTOFILL_ATTR + [
                    'placeholder' => 'FA1234567',
                    'maxlength' => 9,
                    'pattern' => '^FA\d{7}$',
                ],
                'constraints' => [
                    new Assert\Regex(
                        pattern: '/^FA\d{7}$/',
                        message: "Le numéro d'adhésion doit être au format FA1234567 (FA + 7 chiffres).",
                    ),
                    new Assert\NotBlank(
                        message: "Le numéro de facture d'adhésion est obligatoire pour un Coopérateur.",
                        groups: ['cooperateur'],
                    ),
                ],
            ])
            ->add('fare', ChoiceType::class, [
                'choices' => $fareChoices,
                'expanded' => true,
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: array_keys(FareCatalog::all()))],
                'label' => 'Forfait',
            ])
            ->add('eveningGuests', ChoiceType::class, [
                'choices' => array_combine(
                    range(0, FareCatalog::MAX_EVENING_GUESTS),
                    range(0, FareCatalog::MAX_EVENING_GUESTS),
                ),
                // Pas d'option "data" ici : elle écraserait la valeur restituée
                // au retour du récapitulatif. Sans choix présélectionné, le
                // navigateur retient la première entrée, soit 0.
                'constraints' => [new Assert\NotNull()],
                'label' => 'Soirée du jeudi 22 octobre',
                'help' => 'Nombre de personnes, accompagnant compris.',
            ])
            ->add('roomType', ChoiceType::class, [
                'choices' => ['1 grand lit' => 'grand_lit', '2 lits séparés' => 'lits_separes'],
                'placeholder' => '-',
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person'])],
                'label' => 'Type de chambre',
            ])
            ->add('civility', ChoiceType::class, [
                'choices' => ['Madame' => 'mme', 'Monsieur' => 'm'],
                'placeholder' => '-',
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
            ->add('category', ChoiceType::class, [
                'choices' => ['Salarié' => 'salarie', 'Travailleur non salarié' => 'tns'],
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Catégorie',
            ])
            // Participant 2 - mêmes données que le participant principal,
            // requis uniquement pour les forfaits à deux personnes.
            ->add('civility2', ChoiceType::class, [
                'choices' => ['Madame' => 'mme', 'Monsieur' => 'm'],
                'placeholder' => '-',
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person'])],
                'label' => 'Civilité',
            ])
            ->add('lastName2', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person']), new Assert\Length(max: 100)],
                'label' => 'Nom',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('firstName2', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person']), new Assert\Length(max: 100)],
                'label' => 'Prénom',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('mobile2', TelType::class, [
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person'])],
                'label' => 'Mobile',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('email2', EmailType::class, [
                'required' => false,
                'constraints' => [new Assert\NotBlank(groups: ['two_person']), new Assert\Email()],
                'label' => 'Email',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('company2', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 190)],
                'label' => 'Cabinet',
                'attr' => self::NO_AUTOFILL_ATTR,
            ])
            ->add('category2', ChoiceType::class, [
                'choices' => ['Salarié' => 'salarie', 'Travailleur non salarié' => 'tns'],
                'placeholder' => '-',
                'required' => false,
                'label' => 'Catégorie',
            ])
            ->add('motivation', TextareaType::class, [
                'constraints' => [new Assert\NotBlank()],
                'label' => 'Motif de votre inscription et vos attentes',
            ])
            ->add('specialNeeds', TextareaType::class, [
                'required' => false,
                'label' => 'Besoins spécifiques (accessibilité, régime alimentaire…)',
            ])
            ->add('consentAccepted', CheckboxType::class, [
                'constraints' => [new Assert\IsTrue()],
                'label' => "J'accepte les conditions générales de vente",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'validation_groups' => static function (FormInterface $form): array {
                /** @var array{statut?: ?string, fare?: ?string} $data */
                $data = $form->getData() ?? [];

                $groups = ['Default'];
                if ('cooperateur' === ($data['statut'] ?? null)) {
                    $groups[] = 'cooperateur';
                }
                if (FareCatalog::isTwoPerson((string) ($data['fare'] ?? ''))) {
                    $groups[] = 'two_person';
                }

                return $groups;
            },
        ]);
    }
}
