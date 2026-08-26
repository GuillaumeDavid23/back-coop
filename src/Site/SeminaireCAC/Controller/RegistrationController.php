<?php

namespace App\Site\SeminaireCAC\Controller;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\PaymentRepository;
use App\Repository\SiteRepository;
use App\Service\Stripe\PaymentSynchronizer;
use App\Service\Stripe\StripeCheckoutService;
use App\Site\SeminaireCAC\Form\RegistrationStep1Type;
use App\Site\SeminaireCAC\Service\FareCatalog;
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
    private const string SESSION_KEY = 'cac_registration_data';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
        private readonly StripeCheckoutService $stripeCheckout,
        private readonly PaymentSynchronizer $paymentSynchronizer,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/inscription', name: 'registration_step1', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
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

            return $this->redirectToRoute('cac_registration_step2');
        }

        return $this->render('sites/seminaire_cac/registration/step1.html.twig', [
            'form' => $form,
            'selectedFare' => FareCatalog::find($data['fare']),
        ]);
    }

    #[Route('/inscription/recapitulatif', name: 'registration_step2', methods: ['GET'])]
    public function step2(Request $request): Response
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            return $this->redirectToRoute('cac_registration_step1');
        }

        $fare = FareCatalog::find($data['fare']);

        return $this->render('sites/seminaire_cac/registration/step2.html.twig', [
            'data' => $data,
            'fare' => $fare,
        ]);
    }

    #[Route('/inscription/paiement', name: 'registration_pay', methods: ['POST'])]
    public function pay(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('cac_payment', (string) $request->request->get('_token'))) {
            $this->logger->warning('cac.registration.csrf_invalid');

            return $this->redirectToRoute('cac_registration_step2');
        }

        // La case des CGV est "required" dans le gabarit ; on la revérifie ici,
        // le navigateur n'étant pas une barrière fiable.
        if (!$request->request->getBoolean('terms_accepted')) {
            $this->addFlash('cac_notice', "Merci d'accepter les conditions générales de vente et les conditions d'annulation pour valider votre inscription.");

            return $this->redirectToRoute('cac_registration_step2');
        }

        $data = $request->getSession()->get(self::SESSION_KEY);
        if ($data === null) {
            return $this->redirectToRoute('cac_registration_step1');
        }

        $fare = FareCatalog::find($data['fare']);
        if ($fare === null) {
            $this->logger->error('cac.registration.invalid_fare', ['fare' => $data['fare']]);

            return $this->redirectToRoute('cac_registration_step1');
        }

        $site = $this->sites->findByCode('seminaire_cac');
        if ($site === null) {
            $this->logger->error('cac.registration.site_missing');
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

        $this->logger->info('cac.registration.created', [
            'registration_id' => $registration->getId(),
            'fare' => $data['fare'],
            'amount' => $fare['amount'],
            'email' => $data['email'],
        ]);

        $successUrl = $this->generateUrl('cac_registration_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
            . '?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $this->generateUrl('cac_registration_cancelled', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $session = $this->stripeCheckout->createCheckoutSession($registration, $successUrl, $cancelUrl);

        // Trace le paiement en attente dès maintenant : c'est ce qui permet de
        // rafraîchir le statut depuis le BO si le webhook Stripe se perd.
        $this->paymentSynchronizer->createPendingPayment($registration, $session);

        $request->getSession()->remove(self::SESSION_KEY);

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
        if (null !== $registration && $registration->getSite()->getCode() !== 'seminaire_cac') {
            $registration = null;
        }

        return $this->render('sites/seminaire_cac/registration/success.html.twig', [
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
            'PRODID:-//La Coop des Experts//Seminaire CAC//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            // Horaires en UTC (Paris = UTC+1 en novembre/décembre).
            'BEGIN:VEVENT',
            'UID:seminaire-cac-2026-presentiel@clcomevents.fr',
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART:20261124T073000Z',
            'DTEND:20261125T170000Z',
            'SUMMARY:Séminaire CAC - La Coop\' des Experts',
            'LOCATION:Crowne Plaza République\, 10 Place de la République\, 75011 Paris',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:seminaire-cac-2026-distanciel@clcomevents.fr',
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART:20261215T080000Z',
            'DTEND:20261215T120000Z',
            'SUMMARY:Séminaire CAC - 4h en distanciel',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return new Response(implode("\r\n", $lines), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="seminaire-cac-2026.ics"',
        ]);
    }

    #[Route('/inscription/annule', name: 'registration_cancelled', methods: ['GET'])]
    public function cancelled(): Response
    {
        return $this->render('sites/seminaire_cac/registration/cancelled.html.twig');
    }
}
