<?php

namespace App\Site\CoopIATour\Controller;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\PaymentRepository;
use App\Repository\SiteRepository;
use App\Service\Stripe\PaymentSynchronizer;
use App\Service\Stripe\StripeCheckoutService;
use App\Site\LogsInvalidSubmit;
use App\Site\CoopIATour\Form\QuestionnaireType;
use App\Site\CoopIATour\Form\RegistrationStep1Type;
use App\Site\CoopIATour\Service\FareCatalog;
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
    use LogsInvalidSubmit;

    private const string SESSION_KEY = 'iatour_registration_data';
    private const string QUESTIONNAIRE_KEY = 'iatour_questionnaire_data';
    private const string SITE_CODE = 'coop_ia_tour';

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
     * Questionnaire préalable repris du Séminaire IA Deauville : il sert à
     * calibrer les ateliers (niveau, outils, licence Claude). Le forfait choisi
     * sur la page d'accueil le traverse jusqu'au formulaire d'inscription.
     */
    #[Route('/inscription/questionnaire', name: 'registration_questionnaire', methods: ['GET', 'POST'])]
    public function questionnaire(Request $request): Response
    {
        $fare = (string) $request->query->get('fare', '');
        if (null !== FareCatalog::find($fare)) {
            $data = $request->getSession()->get(self::SESSION_KEY) ?? [];
            $request->getSession()->set(self::SESSION_KEY, ['fare' => $fare] + $data);
        }

        $form = $this->createForm(QuestionnaireType::class, $request->getSession()->get(self::QUESTIONNAIRE_KEY));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->set(self::QUESTIONNAIRE_KEY, $form->getData());

            return $this->redirectToRoute('iatour_registration_step1');
        }

        $this->logInvalidSubmit($form, 'iatour.registration.questionnaire_invalid');

        return $this->render('sites/coop_ia_tour/registration/questionnaire.html.twig', ['form' => $form]);
    }

    #[Route('/inscription', name: 'registration_step1', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
        if (null === $request->getSession()->get(self::QUESTIONNAIRE_KEY)) {
            $this->logger->info('iatour.registration.questionnaire_missing', ['method' => $request->getMethod()]);

            // Renvoi explicite : sans message, l'utilisateur qui revient ici
            // après expiration de sa session croit à un bouton défaillant.
            $this->addFlash('iatour_notice', 'Merci de répondre au questionnaire avant de poursuivre votre inscription.');

            return $this->redirectToRoute('iatour_registration_questionnaire', array_filter(['fare' => $request->query->get('fare')]));
        }

        // Retour depuis le récapitulatif : on repart de la saisie déjà faite,
        // pour n'avoir qu'à corriger l'information voulue. Un forfait passé en
        // paramètre (lien « Choisir ce forfait ») prime sur celui retenu.
        $data = $request->getSession()->get(self::SESSION_KEY) ?? [];
        $fare = $request->query->get('fare') ?? $data['fare'] ?? null;
        $data['fare'] = null !== FareCatalog::find((string) $fare)
            ? $fare
            : array_key_first(FareCatalog::all());

        $form = $this->createForm(RegistrationStep1Type::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->set(self::SESSION_KEY, $form->getData());

            return $this->redirectToRoute('iatour_registration_step2');
        }

        $this->logInvalidSubmit($form, 'iatour.registration.step1_invalid');

        return $this->render('sites/coop_ia_tour/registration/step1.html.twig', [
            'form' => $form,
            'selectedFare' => FareCatalog::find($data['fare']),
        ]);
    }

    #[Route('/inscription/recapitulatif', name: 'registration_step2', methods: ['GET'])]
    public function step2(Request $request): Response
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            $this->logger->info('iatour.registration.recap_session_missing');

            return $this->redirectToRoute('iatour_registration_step1');
        }

        $fare = FareCatalog::find($data['fare']);
        $paymentsOpen = $this->paymentsOpen();

        // Fermés, le récapitulatif n'affiche pas le bouton : sans cette trace,
        // rien ne distingue ce visiteur de celui qui renonce à payer.
        $this->logger->info('iatour.registration.recap_viewed', [
            'fare' => $data['fare'],
            'payments_open' => $paymentsOpen,
        ]);

        return $this->render('sites/coop_ia_tour/registration/step2.html.twig', [
            'questionnaire' => $request->getSession()->get(self::QUESTIONNAIRE_KEY, []),
            'data' => $data,
            'fare' => $fare,
            'payments_open' => $paymentsOpen,
        ]);
    }

    #[Route('/inscription/paiement', name: 'registration_pay', methods: ['POST'])]
    public function pay(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('iatour_payment', (string) $request->request->get('_token'))) {
            $this->logger->warning('iatour.registration.csrf_invalid');

            return $this->redirectToRoute('iatour_registration_step2');
        }

        // Paiements fermés depuis l'admin (fiche Site) : rien n'est créé, ni
        // inscription ni session Stripe, même si le formulaire a été posté.
        if (!$this->paymentsOpen()) {
            $this->logger->info('iatour.registration.payments_closed');

            return $this->redirectToRoute('iatour_registration_step2');
        }

        // La case des CGV est "required" dans le gabarit ; on la revérifie ici,
        // le navigateur n'étant pas une barrière fiable.
        if (!$request->request->getBoolean('terms_accepted')) {
            $this->logger->info('iatour.registration.terms_not_accepted');
            $this->addFlash('iatour_notice', "Merci d'accepter les conditions générales de vente et les conditions d'annulation pour valider votre inscription.");

            return $this->redirectToRoute('iatour_registration_step2');
        }

        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            // Session expirée, ou retour arrière depuis la page Stripe : les
            // données sont effacées juste avant d'y rediriger.
            $this->logger->info('iatour.registration.pay_session_missing');

            return $this->redirectToRoute('iatour_registration_step1');
        }

        $fare = FareCatalog::find($data['fare']);
        if ($fare === null) {
            $this->logger->error('iatour.registration.invalid_fare', ['fare' => $data['fare']]);

            return $this->redirectToRoute('iatour_registration_step1');
        }

        $site = $this->sites->findByCode(self::SITE_CODE);
        if ($site === null) {
            $this->logger->error('iatour.registration.site_missing');
            throw $this->createNotFoundException('Site introuvable.');
        }

        $registration = new Registration();
        $registration->setSite($site)
            ->setFareCode($data['fare'])
            ->setFareLabel($fare['label'])
            ->setAmountExclTax($fare['amount'])
            ->setTaxRate(FareCatalog::TAX_RATE)
            ->setAmountInclTax(FareCatalog::inclTax($fare['amount']))
            ->setStatus(RegistrationStatus::PENDING)
            ->setAnswers([
                'motivation' => $data['motivation'],
                'specialNeeds' => $data['specialNeeds'] ?? null,
                'cocktailAttendance' => $data['cocktailAttendance'],
                // Réponses du questionnaire préalable, conservées avec
                // l'inscription pour préparer les ateliers.
                ...$request->getSession()->get(self::QUESTIONNAIRE_KEY, []),
            ]);

        $participant = new Participant();
        $participant->setCivility($data['civility'])
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setEmail($data['email'])
            ->setPhone($data['mobile'])
            ->setCompany($data['company'])
            ->setStatus($data['status'])
            ->setAddress($data['address'])
            ->setPostalCode($data['postalCode'])
            ->setCity($data['city'])
            ->setMotivation($data['motivation'])
            ->setSpecialNeeds($data['specialNeeds'] ?? null)
            ->setConsentAccepted((bool) $data['consentAccepted'])
            ->setAnswers(['cocktailAttendance' => $data['cocktailAttendance']]);

        $registration->addParticipant($participant);

        $this->em->persist($registration);
        $this->em->persist($participant);
        $this->em->flush();

        $this->logger->info('iatour.registration.created', [
            'registration_id' => $registration->getId(),
            'fare' => $data['fare'],
            'amount' => $fare['amount'],
            'email' => $data['email'],
        ]);

        $successUrl = $this->generateUrl('iatour_registration_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
            . '?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->generateUrl('iatour_registration_cancelled', [], UrlGeneratorInterface::ABSOLUTE_URL);

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

        return $this->render('sites/coop_ia_tour/registration/success.html.twig', [
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
            'PRODID:-//La Coop des Experts//Coop IA Tour//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            // Horaires en UTC (Paris = UTC+1 en novembre).
            'BEGIN:VEVENT',
            'UID:coop-ia-tour-2026-paris@clcomevents.fr',
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART:20261126T090000Z',
            'DTEND:20261127T163000Z',
            'SUMMARY:La Coop\' IA Tour - Paris',
            'LOCATION:Comet Bercy\, 40 avenue des Terroirs de France\, 75012 Paris',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return new Response(implode("\r\n", $lines), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="coop-ia-tour-paris-2026.ics"',
        ]);
    }

    #[Route('/inscription/annule', name: 'registration_cancelled', methods: ['GET'])]
    public function cancelled(): Response
    {
        return $this->render('sites/coop_ia_tour/registration/cancelled.html.twig');
    }

    /** Interrupteur de la fiche Site ; fermé si la fiche manque. */
    private function paymentsOpen(): bool
    {
        return $this->sites->findByCode(self::SITE_CODE)?->isPaymentsOpen() ?? false;
    }
}
