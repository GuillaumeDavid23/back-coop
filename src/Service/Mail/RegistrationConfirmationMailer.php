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
 * Mail de confirmation d'inscription, repris du projet backoffice-clcom
 * (StripeSyncService::confirmRegistration) : même objet, même contenu, facture
 * en pièce jointe. L'interlocuteur affiché et sa signature dépendent du site
 * (voir SITE_CONTACTS).
 *
 * Envoyé une seule fois, au moment où le paiement est validé (voir
 * GenerateInvoicePdfMessageHandler, qui déclenche l'envoi une fois la facture
 * réellement disponible).
 *
 * Même machinerie - mêmes copies, même signature, même pièce jointe - pour la
 * facture émise AVANT encaissement (règlement par virement) : là, le message
 * n'annonce pas une inscription confirmée mais une facture à régler (voir
 * sendInvoiceDue).
 */
final class RegistrationConfirmationMailer
{
    private const string FROM = 'ne-pas-repondre@clcomevents.fr';

    /** Copies visibles par défaut - identiques au back-office. */
    private const array CC = [
        'mbroyer@clcom.fr',
        'charles.basset@phoenixfinances.fr',
    ];

    /** Copies cachées par défaut - identiques au back-office (dont la capture comptable). */
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
        'signature' => 'signature.png',
    ];

    /**
     * Réglages propres à un site. Seul le Séminaire CAC en a : il est suivi par
     * Lola BOYER, dont le site public affiche déjà les coordonnées, et ses
     * confirmations ne sont plus adressées à Charles Basset. Les listes sont
     * écrites en entier plutôt que dérivées des constantes ci-dessus : sur des
     * destinataires, une liste explicite se relit sans dérouler de code.
     * Tout site absent de cette table garde les valeurs par défaut.
     */
    private const array SITE_OVERRIDES = [
        'seminaire_cac' => [
            'contact' => [
                'name' => 'Lola BOYER',
                'phone' => '04 78 08 42 74',
                'email' => 'l.boyer@clcom.fr',
                'signature' => 'signature-lola.png',
            ],
            'cc' => [
                'mbroyer@clcom.fr',
                'l.boyer@clcom.fr',
            ],
            'bcc' => [
                'maxime.lefevre@phoenixfinances.fr',
                'pfe_ca_b16586ec@capture.chaintrust.io',
                'd.fourrier@clcom.fr',
            ],
        ],
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly BillingDocumentProvider $documents,
        #[Autowire('%kernel.project_dir%/assets/images')]
        private readonly string $imagesDir,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendForInvoice(Invoice $invoice): void
    {
        $this->doSend($invoice->getRegistration(), $invoice);
    }

    /**
     * Facture émise avant encaissement : c'est elle qui déclenche le règlement
     * par virement (coordonnées bancaires imprimées dessus, voir le gabarit
     * PDF). L'inscription n'est pas encore confirmée, le message ne doit donc
     * pas l'annoncer comme telle.
     */
    public function sendInvoiceDue(Invoice $invoice): void
    {
        $this->doSend($invoice->getRegistration(), $invoice, due: true);
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

    private function doSend(Registration $registration, ?Invoice $invoice, bool $due = false): void
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

        // La pièce jointe est résolue avant tout le reste : si le PDF est
        // indisponible (wkhtmltopdf cassé par une mise à jour du mutualisé,
        // fichier disparu), la confirmation part quand même, sans facture et
        // avec la formulation "adressée sous quelques jours". Une facture non
        // envoyée se rattrape à la main ; un mail de confirmation jamais parti
        // laisse le participant et l'organisatrice sans nouvelle.
        $attachmentPath = null;
        if (null !== $invoice) {
            try {
                $attachmentPath = $this->documents->invoicePath($invoice);
            } catch (\Throwable $e) {
                // critical et non error : la facture est émise et numérotée mais
                // ne partira pas, il faut une reprise humaine (app:resend-confirmation).
                $this->logger->critical('registration.confirmation.invoice_pdf_unavailable', [
                    'invoice_id' => $invoice->getId(),
                    'invoice_number' => $invoice->getNumber(),
                    'registration_id' => $registration->getId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $overrides = self::SITE_OVERRIDES[$site->getCode()] ?? [];
        $contact = $overrides['contact'] ?? self::DEFAULT_CONTACT;
        $cc = $overrides['cc'] ?? self::CC;
        $bcc = $overrides['bcc'] ?? self::BCC;

        $context = [
            'participant' => $participant,
            'registration' => $registration,
            'site' => $site,
            'contact' => $contact,
            'pricing_label' => sprintf(
                '%s - %s € HT',
                $registration->getFareLabel(),
                number_format((float) $registration->getAmountExclTax(), 2, ',', ' '),
            ),
            'total_amount' => number_format((float) $registration->getAmountInclTax(), 2, ',', ' '),
            'invoice' => $invoice,
            'invoice_enabled' => null !== $attachmentPath,
            'answers' => $this->humanizedAnswers($registration->getAnswers(), $participant->getAnswers()),
        ];

        $email = (new Email())
            ->from(self::FROM)
            ->to($participant->getEmail())
            ->cc(...$cc)
            ->bcc(...$bcc)
            ->subject($due
                ? sprintf('Facture %s à régler - %s', $invoice?->getNumber(), $site->getName())
                : 'Confirmation d\'inscription - '.$site->getName())
            ->text($this->twig->render($this->template($site->getCode(), 'txt', $due), $context))
            ->html($this->twig->render($this->template($site->getCode(), 'html', $due), $context));

        $signaturePath = $this->imagesDir.'/'.$contact['signature'];
        if (is_file($signaturePath)) {
            $email->embedFromPath($signaturePath, 'signature.png');
        }

        if (null !== $attachmentPath) {
            $email->attachFromPath($attachmentPath, 'facture.pdf', 'application/pdf');
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

        $this->logger->info($due ? 'invoice.due.sent' : 'registration.confirmation.sent', [
            'invoice_id' => $invoice?->getId(),
            'invoice_number' => $invoice?->getNumber(),
            'registration_id' => $registration->getId(),
            'recipient' => $participant->getEmail(),
            'cc' => $cc,
            'invoice_attached' => null !== $attachmentPath,
        ]);
    }

    /** Fallback par site, même logique que les PDF (voir InvoicePdfGenerator). */
    private function template(string $siteCode, string $format, bool $due = false): string
    {
        $name = $due ? 'invoice_due' : 'confirmation';
        $siteTemplate = sprintf('emails/sites/%s/%s.%s.twig', $siteCode, $name, $format);

        return $this->twig->getLoader()->exists($siteTemplate)
            ? $siteTemplate
            : sprintf('emails/default/%s.%s.twig', $name, $format);
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
