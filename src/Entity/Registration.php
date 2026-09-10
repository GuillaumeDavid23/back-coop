<?php

namespace App\Entity;

use App\Repository\RegistrationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegistrationRepository::class)]
#[ORM\Table(name: 'registration')]
class Registration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    /** Code figé du tarif choisi (ex: "cooperateur") - snapshot, indépendant d'une future évolution des tarifs du site. */
    #[ORM\Column(length: 100)]
    private string $fareCode;

    #[ORM\Column(length: 190)]
    private string $fareLabel;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amountExclTax;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $taxRate = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amountInclTax;

    #[ORM\Column(length: 20, enumType: RegistrationStatus::class)]
    private RegistrationStatus $status = RegistrationStatus::PENDING;

    /** Réponses libres propres à l'événement (motif, besoins spécifiques, options...). */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    /**
     * Date de bascule en gestion manuelle du paiement : à partir de là, c'est
     * l'utilisateur du BO qui tranche si le règlement est arrivé ou non (voir
     * ManualPaymentRecorder), Stripe n'ayant jamais confirmé l'encaissement.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $manualHandlingSince = null;

    /** Email de l'utilisateur du BO qui a basculé l'inscription en gestion manuelle. */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $manualHandlingBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Participant> */
    #[ORM\OneToMany(targetEntity: Participant::class, mappedBy: 'registration', orphanRemoval: true)]
    private Collection $participants;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'registration')]
    private Collection $payments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
        $this->payments = new ArrayCollection();
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

    public function getFareCode(): string
    {
        return $this->fareCode;
    }

    public function setFareCode(string $fareCode): static
    {
        $this->fareCode = $fareCode;

        return $this;
    }

    public function getFareLabel(): string
    {
        return $this->fareLabel;
    }

    public function setFareLabel(string $fareLabel): static
    {
        $this->fareLabel = $fareLabel;

        return $this;
    }

    public function getAmountExclTax(): string
    {
        return $this->amountExclTax;
    }

    public function setAmountExclTax(string $amountExclTax): static
    {
        $this->amountExclTax = $amountExclTax;

        return $this;
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function setTaxRate(string $taxRate): static
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    public function getAmountInclTax(): string
    {
        return $this->amountInclTax;
    }

    public function setAmountInclTax(string $amountInclTax): static
    {
        $this->amountInclTax = $amountInclTax;

        return $this;
    }

    public function getStatus(): RegistrationStatus
    {
        return $this->status;
    }

    public function setStatus(RegistrationStatus $status): static
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    /**
     * Le paiement de cette inscription est suivi à la main : Stripe ne le
     * confirmera pas, c'est l'utilisateur du BO qui note s'il a été reçu.
     */
    public function isManuallyHandled(): bool
    {
        return null !== $this->manualHandlingSince;
    }

    public function getManualHandlingSince(): ?\DateTimeImmutable
    {
        return $this->manualHandlingSince;
    }

    public function getManualHandlingBy(): ?string
    {
        return $this->manualHandlingBy;
    }

    /**
     * Bascule en gestion manuelle. Sans effet si l'inscription y est déjà :
     * la date et l'auteur du premier passage sont ceux qui font foi.
     */
    public function switchToManualHandling(string $by): static
    {
        if (!$this->isManuallyHandled()) {
            $this->manualHandlingSince = new \DateTimeImmutable();
            $this->manualHandlingBy = $by;
            $this->touch();
        }

        return $this;
    }

    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function setAnswers(array $answers): static
    {
        $this->answers = $answers;

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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, Participant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /** Pour la vue détail BO (voir RegistrationCrudController) - une inscription n'a qu'un seul participant en pratique. */
    public function getPrimaryParticipant(): ?Participant
    {
        return $this->participants->first() ?: null;
    }

    /** Pour l'affichage BO (voir RegistrationCrudController) - colonnes "Participant(s)" et "Email". */
    public function getParticipantsFullNames(): string
    {
        return implode(', ', array_map(
            static fn (Participant $participant) => $participant->getFullName(),
            $this->participants->toArray(),
        ));
    }

    public function getParticipantsEmails(): string
    {
        // Certains inscrits n'ont pas d'email à eux (un accompagnant, par
        // exemple) : inutile de les faire apparaître comme une entrée vide.
        return implode(', ', array_filter(array_map(
            static fn (Participant $participant) => $participant->getEmail(),
            $this->participants->toArray(),
        )));
    }

    public function addParticipant(Participant $participant): static
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setRegistration($this);
        }

        return $this;
    }

    public function removeParticipant(Participant $participant): static
    {
        $this->participants->removeElement($participant);

        return $this;
    }

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function getLatestPayment(): ?Payment
    {
        return $this->payments->last() ?: null;
    }

    /**
     * Libellé du statut de l'inscription accordé au genre du participant
     * (civilité "mme" => féminin) - pour l'affichage BO (voir
     * RegistrationCrudController).
     */
    public function getGenderedStatusLabel(): string
    {
        $participant = $this->participants->first() ?: null;
        $feminine = $participant?->getCivility() === 'mme';

        return match ($this->status) {
            RegistrationStatus::PENDING => match (true) {
                $this->isPaymentStillOpen() => 'Paiement en cours',
                // La bascule en manuel est l'information la plus utile sur une
                // inscription non payée : elle dit que quelqu'un s'en occupe.
                $this->isManuallyHandled() => 'Paiement manuel',
                default => 'Paiement non effectué',
            },
            RegistrationStatus::CONFIRMED => $feminine ? 'Confirmée' : 'Confirmé',
            RegistrationStatus::CANCELLED => $feminine ? 'Désinscrite' : 'Désinscrit',
        };
    }

    /**
     * Explication affichée au survol du badge : "En attente" ne disait pas si la
     * personne avait payé, ce qui obligeait l'organisatrice à demander.
     */
    public function getStatusHelp(): ?string
    {
        if ($this->status !== RegistrationStatus::PENDING) {
            return null;
        }

        if ($this->isManuallyHandled()) {
            return sprintf(
                'Paiement suivi à la main depuis le %s (%s) : Stripe ne le confirmera pas. Le bouton "Constater le paiement" permet de le noter payé - ce qui confirme l\'inscription et émet la facture - ou définitivement non payé.',
                $this->manualHandlingSince?->format('d/m/Y'),
                $this->manualHandlingBy ?? 'auteur inconnu',
            );
        }

        return $this->isPaymentStillOpen()
            ? "La page de paiement Stripe a été ouverte il y a moins de 2 heures : le règlement est peut-être encore en cours. Le statut se met à jour tout seul dès que Stripe répond."
            : "Le paiement n'a jamais abouti : page de paiement quittée, ou carte refusée. Rien n'a été encaissé, la personne peut se réinscrire quand elle veut. Le bouton \"Rafraîchir le paiement\" interroge Stripe pour savoir laquelle des deux.";
    }

    public function getStatusBadgeVariant(): string
    {
        return match ($this->status) {
            RegistrationStatus::PENDING => $this->isPaymentStillOpen() || $this->isManuallyHandled() ? 'warning' : 'secondary',
            RegistrationStatus::CONFIRMED => 'success',
            RegistrationStatus::CANCELLED => 'danger',
        };
    }

    /**
     * Distingue les deux situations que "En attente" confondait : un paiement
     * qui peut encore aboutir, et un panier abandonné.
     *
     * Stripe ne notifie l'abandon qu'à l'expiration de la session, jusqu'à 24 h
     * plus tard (voir StripeWebhookController::handleCheckoutExpired). Passé
     * 2 h sans retour, personne n'est plus devant son formulaire de carte : on
     * l'affiche comme non payé sans attendre la confirmation de Stripe, qui
     * viendra remettre le paiement en "échoué" le moment venu.
     */
    private function isPaymentStillOpen(): bool
    {
        $payment = $this->getLatestPayment();

        if ($payment === null || $payment->getStatus() !== PaymentStatus::PENDING) {
            return false;
        }

        // Passée en gestion manuelle, l'inscription n'attend plus rien de
        // Stripe : afficher "paiement en cours" ferait croire à un règlement
        // sur le point d'aboutir, alors que personne n'est devant sa carte.
        if ($this->isManuallyHandled()) {
            return false;
        }

        return $payment->getCreatedAt() > new \DateTimeImmutable('-2 hours');
    }

    /** Libellé humain du dernier paiement - pour l'affichage BO (voir RegistrationCrudController). */
    public function getLatestPaymentStatusLabel(): string
    {
        $payment = $this->getLatestPayment();
        if ($payment === null) {
            return $this->isManuallyHandled() ? 'Manuel - en attente' : '-';
        }

        // Un règlement encaissé hors Stripe se nomme par son moyen (virement,
        // chèque...) : c'est ce que l'organisatrice devra retrouver sur son
        // relevé bancaire, "Réussi" ne lui dirait pas où chercher.
        if ($payment->isManual() && PaymentStatus::SUCCEEDED === $payment->getStatus()) {
            return $payment->getMethod()->label();
        }

        // Vocabulaire du point de vue de l'organisatrice, pas de celui de Stripe :
        // ce qu'elle veut savoir, c'est si l'argent est arrivé.
        return match ($payment->getStatus()) {
            PaymentStatus::PENDING => match (true) {
                $this->isPaymentStillOpen() => 'En cours',
                $this->isManuallyHandled() => 'Manuel - en attente',
                default => 'Non payé',
            },
            PaymentStatus::SUCCEEDED => 'Réussi',
            PaymentStatus::FAILED => 'Abandonné',
            PaymentStatus::REFUNDED => 'Remboursé',
        };
    }

    public function __toString(): string
    {
        return sprintf('Inscription #%d - %s', $this->id ?? 0, $this->fareLabel);
    }
}
