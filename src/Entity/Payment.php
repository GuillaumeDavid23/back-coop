<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_checkout_session', columns: ['stripe_checkout_session_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_intent', columns: ['stripe_payment_intent_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: Registration::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private Registration $registration;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency = 'eur';

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::PENDING;

    /**
     * Carte par défaut : c'est le seul encaissement que la plateforme réalise
     * elle-même (Stripe Checkout). Une autre valeur signale un règlement reçu
     * hors plateforme, validé à la main dans le BO.
     */
    #[ORM\Column(length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $method = PaymentMethod::CARD;

    /**
     * Texte libre saisi lors d'un constat manuel : référence du règlement reçu
     * hors Stripe (n° de chèque, libellé du virement), reprise sur la facture,
     * ou motif du classement quand le règlement n'arrivera pas.
     */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $manualReference = null;

    /** Email de l'utilisateur du BO auteur du constat manuel : sans lui, plus moyen de savoir qui a acquitté ou classé le paiement. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $manuallyValidatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getRegistration(): Registration
    {
        return $this->registration;
    }

    public function setRegistration(Registration $registration): static
    {
        $this->registration = $registration;

        return $this;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(?string $stripeCheckoutSessionId): static
    {
        $this->stripeCheckoutSessionId = $stripeCheckoutSessionId;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }

    /**
     * Lien direct vers la transaction dans le dashboard Stripe, pour vérifier
     * d'un clic ce qui s'est réellement passé côté banque (voir le bouton
     * "Voir sur Stripe" du BO).
     *
     * Le mode (live/test) est déduit du préfixe de l'identifiant de session :
     * une transaction de test vit sur /test, et le lien doit suivre.
     */
    public function getStripeDashboardUrl(): ?string
    {
        $base = 'https://dashboard.stripe.com'.(str_starts_with((string) $this->stripeCheckoutSessionId, 'cs_test_') ? '/test' : '');

        // Seul le PaymentIntent a une page dans le dashboard. Une session
        // Checkout jamais aboutie n'y est consultable que via l'inspecteur du
        // Workbench, où l'identifiant se colle à la main : mieux vaut pas de
        // bouton du tout qu'un bouton qui ouvre une page vide.
        return null !== $this->stripePaymentIntentId
            ? $base.'/payments/'.$this->stripePaymentIntentId
            : null;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function setMethod(PaymentMethod $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getManualReference(): ?string
    {
        return $this->manualReference;
    }

    public function setManualReference(?string $manualReference): static
    {
        $this->manualReference = $manualReference;

        return $this;
    }

    public function getManuallyValidatedBy(): ?string
    {
        return $this->manuallyValidatedBy;
    }

    public function setManuallyValidatedBy(?string $manuallyValidatedBy): static
    {
        $this->manuallyValidatedBy = $manuallyValidatedBy;

        return $this;
    }

    /** Règlement encaissé hors Stripe, constaté à la main dans le BO. */
    public function isManual(): bool
    {
        return PaymentMethod::CARD !== $this->method;
    }

    /**
     * "Mode de règlement" imprimé sur la facture. Pour une carte, la référence
     * utile est la session Checkout ; pour un règlement hors Stripe, c'est la
     * référence saisie par l'utilisateur (n° de chèque, libellé du virement).
     */
    public function getSettlementLabel(): string
    {
        if (!$this->isManual()) {
            return 'CB - '.($this->stripeCheckoutSessionId ?? 'Manuel');
        }

        return $this->method->label().(null !== $this->manualReference ? ' - '.$this->manualReference : '');
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
