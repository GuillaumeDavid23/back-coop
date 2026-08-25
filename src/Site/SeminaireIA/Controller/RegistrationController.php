<?php

namespace App\Site\SeminaireIA\Controller;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\PaymentRepository;
use App\Repository\SiteRepository;
use App\Service\Stripe\PaymentSynchronizer;
use App\Service\Stripe\StripeCheckoutService;
use App\Site\SeminaireIA\Form\QuestionnaireType;
use App\Site\SeminaireIA\Form\RegistrationStep1Type;
use App\Site\SeminaireIA\Service\FareCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RegistrationController extends AbstractController
{
    private const string SESSION_KEY = 'ia_registration_data';
    private const string QUESTIONNAIRE_KEY = 'ia_questionnaire_data';
    private const string SITE_CODE = 'seminaire_ia';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
        private readonly StripeCheckoutService $stripeCheckout,
        private readonly PaymentSynchronizer $paymentSynchronizer,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Questionnaire préalable : sert à calibrer les ateliers pratiques. Placé
     * avant le formulaire d'inscription à la demande de l'organisatrice.
     */
    #[Route('/inscription/questionnaire', name: 'registration_questionnaire', methods: ['GET', 'POST'])]
    public function questionnaire(Request $request): Response
    {
        $form = $this->createForm(QuestionnaireType::class, $request->getSession()->get(self::QUESTIONNAIRE_KEY));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->set(self::QUESTIONNAIRE_KEY, $form->getData());

            return $this->redirectToRoute('ia_registration_step1');
        }

        return $this->render('sites/seminaire_ia/registration/questionnaire.html.twig', ['form' => $form]);
    }

    #[Route('/inscription', name: 'registration_step1', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
        if (null === $request->getSession()->get(self::QUESTIONNAIRE_KEY)) {
            return $this->redirectToRoute('ia_registration_questionnaire');
        }

        $fare = $request->query->get('fare');
        $data = [
            'fare' => null !== FareCatalog::find((string) $fare) ? $fare : null,
        ];

        $form = $this->createForm(RegistrationStep1Type::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->set(self::SESSION_KEY, $form->getData());

            return $this->redirectToRoute('ia_registration_step2');
        }

        return $this->render('sites/seminaire_ia/registration/step1.html.twig', [
            'form' => $form,
            'fares' => FareCatalog::all(),
            'eveningPrices' => FareCatalog::eveningPrices(),
        ]);
    }

    #[Route('/inscription/recapitulatif', name: 'registration_step2', methods: ['GET'])]
    public function step2(Request $request): Response
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            return $this->redirectToRoute('ia_registration_step1');
        }

        $amounts = $this->computeAmounts($data);
        if ($amounts === null) {
            return $this->redirectToRoute('ia_registration_step1');
        }

        return $this->render('sites/seminaire_ia/registration/step2.html.twig', [
            'questionnaire' => $request->getSession()->get(self::QUESTIONNAIRE_KEY, []),
            'data' => $data,
            'fare' => FareCatalog::find($data['fare']),
            'statusLabel' => FareCatalog::statuses()[$data['statut']] ?? $data['statut'],
            'fareLabel' => $amounts['label'],
            'amountExclTax' => $amounts['exclTax'],
            'amountInclTax' => $amounts['inclTax'],
            'isTwoPerson' => FareCatalog::isTwoPerson($data['fare']),
        ]);
    }

    #[Route('/inscription/paiement', name: 'registration_pay', methods: ['POST'])]
    public function pay(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ia_payment', (string) $request->request->get('_token'))) {
            $this->logger->warning('ia.registration.csrf_invalid');

            return $this->redirectToRoute('ia_registration_step2');
        }

        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            return $this->redirectToRoute('ia_registration_step1');
        }

        $amounts = $this->computeAmounts($data);
        if ($amounts === null) {
            $this->logger->error('ia.registration.invalid_fare', [
                'fare' => $data['fare'] ?? null,
                'statut' => $data['statut'] ?? null,
            ]);

            return $this->redirectToRoute('ia_registration_step1');
        }

        $site = $this->sites->findByCode(self::SITE_CODE);
        if ($site === null) {
            $this->logger->error('ia.registration.site_missing');
            throw $this->createNotFoundException('Site introuvable.');
        }

        $isTwoPerson = FareCatalog::isTwoPerson($data['fare']);
        $isCooperateur = 'cooperateur' === $data['statut'];
        $hasEvening = (bool) ($data['eveningOption'] ?? false) && FareCatalog::allowsEveningOption($data['fare']);

        $registration = new Registration();
        $registration->setSite($site)
            ->setFareCode($data['fare'])
            ->setFareLabel($amounts['label'])
            ->setAmountExclTax($amounts['exclTax'])
            ->setTaxRate(FareCatalog::TAX_RATE)
            ->setAmountInclTax($amounts['inclTax'])
            ->setStatus(RegistrationStatus::PENDING)
            ->setAnswers(array_filter([
                'statut' => $data['statut'],
                'subscriptionNumber' => $isCooperateur ? $data['subscriptionNumber'] : null,
                'category' => $data['category'],
                'eveningOption' => $hasEvening ?: null,
                'roomType' => $isTwoPerson ? $data['roomType'] : null,
                'motivation' => $data['motivation'],
                'specialNeeds' => $data['specialNeeds'] ?? null,
                // Réponses du questionnaire préalable, conservées avec
                // l'inscription pour préparer les ateliers.
                ...$request->getSession()->get(self::QUESTIONNAIRE_KEY, []),
            ], static fn ($value) => null !== $value && '' !== $value && [] !== $value));

        $participant = new Participant();
        $participant->setCivility($data['civility'])
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setEmail($data['email'])
            ->setPhone($data['mobile'])
            ->setCompany($data['company'])
            ->setStatus($data['category'])
            ->setAddress($data['address'])
            ->setPostalCode($data['postalCode'])
            ->setCity($data['city'])
            ->setMotivation($data['motivation'])
            ->setSpecialNeeds($data['specialNeeds'] ?? null)
            ->setConsentAccepted((bool) $data['consentAccepted'])
            ->setAnswers([]);

        $registration->addParticipant($participant);
        $this->em->persist($registration);
        $this->em->persist($participant);

        if ($isTwoPerson) {
            $companion = new Participant();
            $companion->setCivility($data['civility2'])
                ->setFirstName($data['firstName2'])
                ->setLastName($data['lastName2'])
                ->setEmail($data['email2'])
                ->setPhone($data['mobile2'])
                ->setCompany($data['company2'] ?? null)
                ->setStatus($data['category2'] ?? null)
                ->setConsentAccepted((bool) $data['consentAccepted'])
                ->setAnswers([]);

            $registration->addParticipant($companion);
            $this->em->persist($companion);
        }

        $this->em->flush();

        $this->logger->info('ia.registration.created', [
            'registration_id' => $registration->getId(),
            'fare' => $data['fare'],
            'statut' => $data['statut'],
            'amount_incl_tax' => $amounts['inclTax'],
            'email' => $data['email'],
        ]);

        $successUrl = $this->generateUrl('ia_registration_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
            . '?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->generateUrl('ia_registration_cancelled', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $session = $this->stripeCheckout->createCheckoutSession($registration, $successUrl, $cancelUrl);

        // Trace le paiement en attente dès maintenant : c'est ce qui permet de
        // rafraîchir le statut depuis le BO si le webhook Stripe se perd.
        $this->paymentSynchronizer->createPendingPayment($registration, $session);

        $request->getSession()->remove(self::SESSION_KEY);
        $request->getSession()->remove(self::QUESTIONNAIRE_KEY);

        return $this->redirect($session->url);
    }

    /**
     * Page de remerciement. Le session_id fourni par Stripe ne sert QU'À
     * afficher le bon récapitulatif : il ne valide jamais un paiement, seul le
     * webhook (ou le rafraîchissement manuel depuis le BO) fait foi.
     */
    #[Route('/inscription/merci', name: 'registration_success', methods: ['GET'])]
    public function success(Request $request, PaymentRepository $payments): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        $payment = '' !== $sessionId ? $payments->findByStripeCheckoutSessionId($sessionId) : null;
        $registration = $payment?->getRegistration();

        // Ne jamais afficher l'inscription d'un autre site que celui-ci.
        if (null !== $registration && $registration->getSite()->getCode() !== self::SITE_CODE) {
            $registration = null;
        }

        return $this->render('sites/seminaire_ia/registration/success.html.twig', [
            'registration' => $registration,
            'participant' => $registration?->getPrimaryParticipant(),
            'isConfirmed' => $registration?->getStatus() === RegistrationStatus::CONFIRMED,
        ]);
    }

    /** Fichier .ics proposé sur la page de remerciement ("Ajouter à mon agenda"). */
    #[Route('/inscription/agenda.ics', name: 'registration_ics', methods: ['GET'])]
    public function calendar(): Response
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CLCOM Academy//Seminaire IA Deauville//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            // Horaires en UTC (Paris = UTC+2 en octobre, heure d'été).
            'BEGIN:VEVENT',
            'UID:seminaire-ia-deauville-2026@clcomevents.fr',
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART:20261022T090000Z',
            'DTEND:20261023T150000Z',
            'SUMMARY:Séminaire IA — CLCOM Academy',
            'LOCATION:Hôtel du Golf\, Le Mont Canisy\, 14800 Saint-Arnoult',
            'DESCRIPTION:Comment intégrer l\'IA dans vos pratiques — deux journées de formation à Deauville.',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return new Response(implode("\r\n", $lines), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="seminaire-ia-2026.ics"',
        ]);
    }

    #[Route('/inscription/annule', name: 'registration_cancelled', methods: ['GET'])]
    public function cancelled(): Response
    {
        return $this->render('sites/seminaire_ia/registration/cancelled.html.twig');
    }

    /**
     * Montants et libellé recalculés depuis la grille à chaque étape — jamais
     * depuis la session seule, pour qu'un prix modifié entre deux étapes ne
     * puisse pas être encaissé sur une ancienne valeur.
     *
     * @return array{label: string, exclTax: string, inclTax: string}|null
     */
    private function computeAmounts(array $data): ?array
    {
        $fare = (string) ($data['fare'] ?? '');
        $statut = (string) ($data['statut'] ?? '');
        $evening = (bool) ($data['eveningOption'] ?? false);

        $exclTax = FareCatalog::totalExclTax($fare, $statut, $evening);
        if (null === $exclTax) {
            return null;
        }

        return [
            'label' => FareCatalog::fareLabel($fare, $statut, $evening),
            'exclTax' => $exclTax,
            'inclTax' => FareCatalog::inclTax($exclTax),
        ];
    }
}
