<?php

namespace App\Form;

use App\Service\Billing\ManualRegistrationCreator;
use App\Site\SeminaireIA\Service\FareCatalog as IaFareCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Inscription saisie depuis le BO. Part des formulaires publics du site, pour
 * que les questions posées restent exactement les mêmes, et n'en adapte que ce
 * qui change quand c'est un organisateur qui saisit.
 *
 *  - complete : formulaire et questionnaire du site ;
 *  - quick    : seulement l'identité, la formule et les coordonnées utiles ;
 *  - fill     : ajoute les questions à une inscription rapide, sans toucher au
 *               prix (formule et statut tarifaire disparaissent du formulaire).
 */
final class ManualRegistrationType extends AbstractType
{
    public const string MODE_COMPLETE = 'complete';
    public const string MODE_QUICK = 'quick';
    public const string MODE_FILL = 'fill';

    /** Champs tenant du questionnaire ou du dossier : absents de la saisie rapide. */
    private const array QUICK_REMOVED = [
        'motivation', 'specialNeeds', 'cocktailAttendance', 'status',
        'category', 'roomType', 'company2', 'category2',
    ];

    /** Champs obligatoires côté public mais facultatifs en saisie rapide : libellé, longueur de la colonne. */
    private const array QUICK_OPTIONAL = [
        'company' => ['Cabinet', 190],
        'address' => ['Adresse', 190],
        'postalCode' => ['Code postal', 20],
        'city' => ['Ville', 100],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $siteCode = $options['site_code'];
        $mode = $options['mode'];
        $forms = ManualRegistrationCreator::formsFor($siteCode)
            ?? throw new \InvalidArgumentException(sprintf('Aucun formulaire d\'inscription pour le site "%s".', $siteCode));

        $builder->add('registration', $forms['registration'], ['label' => false] + $this->quickValidation($siteCode, $mode));
        $details = $builder->get('registration');

        $this->configureFare($details, $siteCode, $mode);

        if (self::MODE_QUICK === $mode) {
            $this->reduceToEssentials($details);
        } elseif (null !== $forms['questionnaire']) {
            $builder->add('questionnaire', $forms['questionnaire'], ['label' => false]);
        }

        // Un organisateur ne peut qu'indiquer ce que le participant a accepté :
        // la case publique, obligatoire, lui ferait accepter à sa place.
        $details->remove('consentAccepted')->add('consentAccepted', CheckboxType::class, [
            'required' => false,
            'label' => 'Le participant a accepté les conditions générales de vente',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'mode' => self::MODE_COMPLETE]);
        $resolver->setRequired('site_code');
        $resolver->setAllowedTypes('site_code', 'string');
        $resolver->setAllowedValues('mode', [self::MODE_COMPLETE, self::MODE_QUICK, self::MODE_FILL]);
    }

    private function configureFare(FormBuilderInterface $details, string $siteCode, string $mode): void
    {
        if (self::MODE_FILL === $mode) {
            $details->remove('fare')->remove('statut')->remove('eveningGuests');

            return;
        }

        $choices = ManualRegistrationCreator::simpleFareChoices($siteCode);
        if ([] !== $choices) {
            // Côté public, le forfait est choisi sur la page d'accueil et le
            // champ est caché : ici, il doit se choisir dans le formulaire.
            $details->remove('fare')->add('fare', ChoiceType::class, [
                'choices' => $choices,
                'placeholder' => '-',
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: array_values($choices))],
                'label' => 'Formule',
                'priority' => 10,
            ]);
        }
    }

    private function reduceToEssentials(FormBuilderInterface $details): void
    {
        foreach (self::QUICK_REMOVED as $field) {
            $details->remove($field);
        }

        $details->remove('mobile')->add('mobile', TelType::class, [
            'required' => false,
            'label' => 'Mobile',
            'constraints' => [new Assert\Length(max: 30)],
        ]);

        foreach (self::QUICK_OPTIONAL as $field => [$label, $maxLength]) {
            $details->remove($field)->add($field, TextType::class, [
                'required' => false,
                'label' => $label,
                'constraints' => [new Assert\Length(max: $maxLength)],
            ]);
        }
    }

    /**
     * Au Séminaire IA, la saisie rapide ne garde que ce qui fixe le prix et
     * identifie les personnes : ni numéro d'adhésion, ni coordonnées du second
     * expert-comptable ne bloquent l'inscription.
     *
     * @return array<string, mixed> options du sous-formulaire
     */
    private function quickValidation(string $siteCode, string $mode): array
    {
        if (self::MODE_QUICK !== $mode || null === ManualRegistrationCreator::displayRules($siteCode)) {
            return [];
        }

        return ['validation_groups' => static function (FormInterface $form): array {
            $fare = (string) (($form->getData() ?? [])['fare'] ?? '');

            return array_merge(
                ['Default'],
                IaFareCatalog::isTwoPerson($fare) ? ['two_person'] : [],
                IaFareCatalog::allowsEveningOption($fare) ? ['evening'] : [],
            );
        }];
    }
}
