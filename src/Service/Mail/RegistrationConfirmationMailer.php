<?php

namespace App\Service\Mail;

use App\Entity\Invoice;
use App\Entity\Registration;
use App\Service\Billing\BillingDocumentProvider;
use App\Service\Export\AnswerHumanizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Mail de confirmation d'inscription, repris à l'identique du projet
 * backoffice-clcom (StripeSyncService::confirmRegistration) : mêmes
 * destinataires en copie, même objet, même contenu, facture en pièce jointe.
 *
 * Envoyé une seule fois, au moment où le paiement est validé (voir
 * GenerateInvoicePdfMessageHandler, qui déclenche l'envoi une fois la facture
 * réellement disponible).
 */
final class RegistrationConfirmationMailer
{
    private const string FROM = 'ne-pas-repondre@clcomevents.fr';

    /** Copies visibles — identiques au back-office. */
    private const array CC = [
        'mbroyer@clcom.fr',
        'charles.basset@phoenixfinances.fr',
    ];

    /** Copies cachées — identiques au back-office (dont la capture comptable). */
    private const array BCC = [
        'maxime.lefevre@phoenixfinances.fr',
        'l.boyer@clcom.fr',
        'pfe_ca_b16586ec@capture.chaintrust.io',
        'd.fourrier@clcom.fr',
    ];

    /** Interlocuteur affiché dans le corps du mail et la signature. */
    private const array DEFAULT_CONTACT = [
        'name' => 'Marion BROYER',
        'phone' => '06 88 20 58 12',
        'email' => 'mbroyer@clcom.fr',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly BillingDocumentProvider $documents,
        #[Autowire('%kernel.project_dir%/assets/images/signature.png')]
        private readonly string $signaturePath,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendForInvoice(Invoice $invoice): void
    {
        $this->doSend($invoice->getRegistration(), $invoice);
    }

    /**
     * Confirmation sans facture, pour les sites dont la facturation est
     * désactivée (Site::invoicingEnabled) : même email, sans pièce jointe et
     * sans promettre de facture dans le corps du message.
     */
    public function sendForRegistration(Registration $registration): void
    {
        $this->doSend($registration, null);
    }

    private function doSend(Registration $registration, ?Invoice $invoice): void
    {
        $participant = $registration->getPrimaryParticipant();
        $site = $registration->getSite();

        if (null === $participant) {
            $this->logger->error('registration.confirmation.no_participant', [
                'invoice_id' => $invoice?->getId(),
                'registration_id' => $registration->getId(),
            ]);

            return;
        }

        $context = [
            'participant' => $participant,
            'registration' => $registration,
            'site' => $site,
            'contact' => self::DEFAULT_CONTACT,
            'pricing_label' => sprintf(
                '%s – %s € HT',
                $registration->getFareLabel(),
                number_format((float) $registration->getAmountExclTax(), 2, ',', ' '),
            ),
            'total_amount' => number_format((float) $registration->getAmountInclTax(), 2, ',', ' '),
            'invoice_enabled' => null !== $invoice,
            'answers' => $this->humanizedAnswers($registration->getAnswers(), $participant->getAnswers()),
        ];

        $email = (new Email())
            ->from(self::FROM)
            ->to($participant->getEmail())
            ->cc(...self::CC)
            ->bcc(...self::BCC)
            ->subject('Confirmation d\'inscription – '.$site->getName())
            ->text($this->twig->render($this->template($site->getCode(), 'txt'), $context))
            ->html($this->twig->render($this->template($site->getCode(), 'html'), $context));

        if (is_file($this->signaturePath)) {
            $email->embedFromPath($this->signaturePath, 'signature.png');
        }

        if (null !== $invoice) {
            $email->attachFromPath($this->documents->invoicePath($invoice), 'facture.pdf', 'application/pdf');
        }

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('registration.confirmation.send_failed', [
                'invoice_id' => $invoice?->getId(),
                'recipient' => $participant->getEmail(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('registration.confirmation.sent', [
            'invoice_id' => $invoice?->getId(),
            'invoice_number' => $invoice?->getNumber(),
            'registration_id' => $registration->getId(),
            'recipient' => $participant->getEmail(),
            'cc' => self::CC,
        ]);
    }

    /** Fallback par site, même logique que les PDF (voir InvoicePdfGenerator). */
    private function template(string $siteCode, string $format): string
    {
        $siteTemplate = sprintf('emails/sites/%s/confirmation.%s.twig', $siteCode, $format);

        return $this->twig->getLoader()->exists($siteTemplate)
            ? $siteTemplate
            : sprintf('emails/default/confirmation.%s.twig', $format);
    }

    /**
     * Réponses libres de l'inscription rendues lisibles ("cocktailAttendance" =>
     * "Cocktail attendance" / "Oui"), sans rien coder en dur : un futur
     * événement avec d'autres questions s'affichera automatiquement.
     */
    private function humanizedAnswers(array $registrationAnswers, array $participantAnswers): array
    {
        $merged = array_merge($registrationAnswers, $participantAnswers);
        $skip = ['motivation', 'specialNeeds'];

        $answers = [];
        foreach ($merged as $key => $value) {
            if (\in_array($key, $skip, true) || null === $value || '' === $value) {
                continue;
            }
            $answers[AnswerHumanizer::key($key)] = AnswerHumanizer::value($value);
        }

        return $answers;
    }
}
