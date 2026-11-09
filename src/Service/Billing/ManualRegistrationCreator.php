<?php

namespace App\Service\Billing;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Site\CoopIATour\Form\QuestionnaireType as IaTourQuestionnaireType;
use App\Site\CoopIATour\Form\RegistrationStep1Type as IaTourStep1Type;
use App\Site\CoopIATour\Service\FareCatalog as IaTourFareCatalog;
use App\Site\SeminaireCAC\Form\RegistrationStep1Type as CacStep1Type;
use App\Site\SeminaireCAC\Service\FareCatalog as CacFareCatalog;
use App\Site\SeminaireIA\Form\QuestionnaireType as IaQuestionnaireType;
use App\Site\SeminaireIA\Form\RegistrationStep1Type as IaStep1Type;
use App\Site\SeminaireIA\Service\FareCatalog as IaFareCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inscription saisie depuis le BO, avec les formulaires et la grille tarifaire
 * du site concerné. Le tarif n'est jamais saisi : il est recalculé ici.
 *
 * L'inscription naît en attente et en gestion manuelle : le règlement se note
 * ensuite sur l'écran habituel (voir ManualPaymentRecorder), seul autorisé à
 * la confirmer et à déclencher la facture.
 */
final class ManualRegistrationCreator
{
    private const string SITE_IA = 'seminaire_ia';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function supports(?Site $site): bool
    {
        return null !== self::formsFor($site?->getCode() ?? '');
    }

    /** @return array{registration: class-string, questionnaire: class-string|null}|null */
    public static function formsFor(string $siteCode): ?array
    {
        return match ($siteCode) {
            'seminaire_cac' => ['registration' => CacStep1Type::class, 'questionnaire' => null],
            'coop_ia_tour' => ['registration' => IaTourStep1Type::class, 'questionnaire' => IaTourQuestionnaireType::class],
            self::SITE_IA => ['registration' => IaStep1Type::class, 'questionnaire' => IaQuestionnaireType::class],
            default => null,
        };
    }

    /**
     * Formules au tarif unique, pour la liste du BO. Vide pour le Séminaire IA,
     * dont le prix dépend aussi du statut : son formulaire a déjà ces champs.
     *
     * @return array<string, string> libellé affiché => code
     */
    public static function simpleFareChoices(string $siteCode): array
    {
        $catalog = self::simpleCatalog($siteCode);
        if (null === $catalog) {
            return [];
        }

        $choices = [];
        foreach ($catalog::all() as $code => $fare) {
            $choices[sprintf('%s - %s € TTC', $fare['label'], $catalog::inclTax($fare['amount']))] = $code;
        }

        return $choices;
    }

    /**
     * Quels champs conditionnels afficher selon le forfait, repris de la grille
     * du Séminaire IA. Confort de saisie : la validation serveur reste seule juge.
     *
     * @return array{twoPerson: list<string>, companion: list<string>, evening: list<string>}|null
     */
    public static function displayRules(string $siteCode): ?array
    {
        if (self::SITE_IA !== $siteCode) {
            return null;
        }

        $codes = array_keys(IaFareCatalog::all());

        return [
            'twoPerson' => array_values(array_filter($codes, IaFareCatalog::isTwoPerson(...))),
            'companion' => array_values(array_filter($codes, IaFareCatalog::isCompanionFare(...))),
            'evening' => array_values(array_filter($codes, IaFareCatalog::allowsEveningOption(...))),
        ];
    }

    /** Inscription rapide restant à compléter : une inscription désinscrite n'a plus de dossier à préparer. */
    public function canComplete(Registration $registration): bool
    {
        return RegistrationStatus::CANCELLED !== $registration->getStatus()
            && $registration->isFormIncomplete()
            && self::supports($registration->getSite());
    }

    /**
     * @param array<string, mixed> $data          champs du formulaire d'inscription du site
     * @param array<string, mixed> $questionnaire réponses au questionnaire, vide en inscription rapide
     */
    public function create(Site $site, array $data, array $questionnaire, string $createdBy): Registration
    {
        $pricing = $this->pricing($site->getCode(), $data);

        $registration = (new Registration())
            ->setSite($site)
            ->setFareCode((string) $data['fare'])
            ->setFareLabel($pricing['label'])
            ->setAmountExclTax($pricing['exclTax'])
            ->setTaxRate($pricing['taxRate'])
            ->setAmountInclTax($pricing['inclTax'])
            ->setStatus(RegistrationStatus::PENDING)
            ->switchToManualHandling($createdBy);

        $this->em->persist($registration);
        $this->fill($registration, $data, $questionnaire);
        $this->em->flush();

        $this->logger->info('registration.manual.created', [
            'registration_id' => $registration->getId(),
            'site' => $site->getCode(),
            'fare' => $registration->getFareCode(),
            'amount' => $registration->getAmountInclTax(),
            'questions_answered' => !$registration->isFormIncomplete(),
            'created_by' => $createdBy,
        ]);

        return $registration;
    }

    /**
     * Ajoute le formulaire et les questions à une inscription rapide. Formule,
     * statut tarifaire et montants restent ceux déjà retenus.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $questionnaire
     */
    public function complete(Registration $registration, array $data, array $questionnaire, string $completedBy): void
    {
        $this->fill($registration, $data, $questionnaire);
        $registration->touch();
        $this->em->flush();

        $this->logger->info('registration.manual.completed', [
            'registration_id' => $registration->getId(),
            'completed_by' => $completedBy,
        ]);
    }

    /**
     * Données de départ de « Compléter le formulaire ». Le forfait et le statut
     * y figurent même sans champ à l'écran : les règles de validation du
     * formulaire du Séminaire IA en dépendent.
     *
     * @return array{registration: array<string, mixed>}
     */
    public function prefill(Registration $registration): array
    {
        $answers = $registration->getAnswers();
        $participants = array_values($registration->getParticipants()->toArray());
        $primary = $participants[0] ?? null;
        $second = $participants[1] ?? null;

        return ['registration' => [
            'fare' => $registration->getFareCode(),
            'statut' => $answers['statut'] ?? null,
            'subscriptionNumber' => $answers['subscriptionNumber'] ?? null,
            'eveningGuests' => $answers['eveningGuests'] ?? 0,
            'civility' => $primary?->getCivility(),
            'firstName' => $primary?->getFirstName(),
            'lastName' => $primary?->getLastName(),
            'email' => $primary?->getEmail(),
            'mobile' => $primary?->getPhone(),
            'company' => $primary?->getCompany(),
            'address' => $primary?->getAddress(),
            'postalCode' => $primary?->getPostalCode(),
            'city' => $primary?->getCity(),
            'consentAccepted' => $primary?->isConsentAccepted() ?? false,
            'civility2' => $second?->getCivility(),
            'firstName2' => $second?->getFirstName(),
            'lastName2' => $second?->getLastName(),
            'email2' => $second?->getEmail() ?: null,
            'mobile2' => $second?->getPhone(),
        ]];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $questionnaire
     */
    private function fill(Registration $registration, array $data, array $questionnaire): void
    {
        $isIa = self::SITE_IA === $registration->getSite()->getCode();
        $fare = $registration->getFareCode();
        $isTwoPerson = $isIa && IaFareCatalog::isTwoPerson($fare);

        $answers = $isIa
            ? [
                'statut' => $data['statut'] ?? null,
                'subscriptionNumber' => 'cooperateur' === ($data['statut'] ?? null) ? ($data['subscriptionNumber'] ?? null) : null,
                'category' => $data['category'] ?? null,
                'eveningGuests' => IaFareCatalog::eveningGuests($data['eveningGuests'] ?? 0, $fare) ?: null,
                'roomType' => $isTwoPerson ? ($data['roomType'] ?? null) : null,
            ]
            : ['cocktailAttendance' => $data['cocktailAttendance'] ?? null];

        $registration->setAnswers(self::withoutEmpty([
            ...$registration->getAnswers(),
            ...$answers,
            'motivation' => $data['motivation'] ?? null,
            'specialNeeds' => $data['specialNeeds'] ?? null,
            ...$questionnaire,
        ]));

        $participants = array_values($registration->getParticipants()->toArray());
        $consent = (bool) ($data['consentAccepted'] ?? false);

        ($participants[0] ?? $this->newParticipant($registration))
            ->setCivility($data['civility'] ?? null)
            ->setFirstName((string) $data['firstName'])
            ->setLastName((string) $data['lastName'])
            ->setEmail((string) $data['email'])
            ->setPhone($data['mobile'] ?? null)
            ->setCompany($data['company'] ?? null)
            ->setStatus($isIa ? ($data['category'] ?? null) : ($data['status'] ?? null))
            ->setAddress($data['address'] ?? null)
            ->setPostalCode($data['postalCode'] ?? null)
            ->setCity($data['city'] ?? null)
            ->setMotivation($data['motivation'] ?? null)
            ->setSpecialNeeds($data['specialNeeds'] ?? null)
            ->setConsentAccepted($consent)
            ->setAnswers($isIa ? [] : self::withoutEmpty(['cocktailAttendance' => $data['cocktailAttendance'] ?? null]));

        if ($isTwoPerson) {
            // Même règle que le formulaire public : l'accompagnant peut ne pas
            // avoir d'email, la colonne attend alors une chaîne vide.
            ($participants[1] ?? $this->newParticipant($registration))
                ->setCivility($data['civility2'] ?? null)
                ->setFirstName((string) ($data['firstName2'] ?? ''))
                ->setLastName((string) ($data['lastName2'] ?? ''))
                ->setEmail((string) ($data['email2'] ?? ''))
                ->setPhone($data['mobile2'] ?? null)
                ->setCompany($data['company2'] ?? null)
                ->setStatus($data['category2'] ?? null)
                ->setConsentAccepted($consent);
        }
    }

    private function newParticipant(Registration $registration): Participant
    {
        $participant = new Participant();
        $registration->addParticipant($participant);
        $this->em->persist($participant);

        return $participant;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{label: string, exclTax: string, taxRate: string, inclTax: string}
     */
    private function pricing(string $siteCode, array $data): array
    {
        $fare = (string) ($data['fare'] ?? '');

        if (self::SITE_IA === $siteCode) {
            $statut = (string) ($data['statut'] ?? '');
            $exclTax = IaFareCatalog::totalExclTax($fare, $statut, IaFareCatalog::eveningGuests($data['eveningGuests'] ?? 0, $fare));
            if (null === $exclTax) {
                throw new \InvalidArgumentException(sprintf('Tarif introuvable pour le forfait "%s" et le statut "%s".', $fare, $statut));
            }

            return [
                'label' => IaFareCatalog::fareLabel($fare, $statut),
                'exclTax' => $exclTax,
                'taxRate' => IaFareCatalog::TAX_RATE,
                'inclTax' => IaFareCatalog::inclTax($exclTax),
            ];
        }

        $catalog = self::simpleCatalog($siteCode);
        $selected = $catalog::find($fare) ?? throw new \InvalidArgumentException(sprintf('Forfait "%s" inconnu.', $fare));

        return [
            'label' => $selected['label'],
            'exclTax' => $selected['amount'],
            'taxRate' => $catalog::TAX_RATE,
            'inclTax' => $catalog::inclTax($selected['amount']),
        ];
    }

    /** @return class-string<CacFareCatalog|IaTourFareCatalog>|null */
    private static function simpleCatalog(string $siteCode): ?string
    {
        return match ($siteCode) {
            'seminaire_cac' => CacFareCatalog::class,
            'coop_ia_tour' => IaTourFareCatalog::class,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => null !== $value && '' !== $value && [] !== $value);
    }
}
