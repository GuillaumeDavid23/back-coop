<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'site')]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190, unique: true)]
    private string $domain;

    #[ORM\Column]
    private bool $enabled = true;

    /**
     * Certains événements sont encaissés sans émettre de facture (ex: Séminaire
     * IA — encaissement CLCOM Academy, facturation gérée hors plateforme) : à
     * false, le paiement confirmé envoie directement l'email de confirmation
     * sans passer par la génération de facture.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $invoicingEnabled = true;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $invoicePrefix = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $invoiceSuffix = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $creditNotePrefix = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $creditNoteSuffix = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'sites')]
    private Collection $users;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isInvoicingEnabled(): bool
    {
        return $this->invoicingEnabled;
    }

    public function setInvoicingEnabled(bool $invoicingEnabled): static
    {
        $this->invoicingEnabled = $invoicingEnabled;

        return $this;
    }

    public function getInvoicePrefix(): ?string
    {
        return $this->invoicePrefix;
    }

    public function setInvoicePrefix(?string $invoicePrefix): static
    {
        $this->invoicePrefix = $invoicePrefix;

        return $this;
    }

    public function getInvoiceSuffix(): ?string
    {
        return $this->invoiceSuffix;
    }

    public function setInvoiceSuffix(?string $invoiceSuffix): static
    {
        $this->invoiceSuffix = $invoiceSuffix;

        return $this;
    }

    public function getCreditNotePrefix(): ?string
    {
        return $this->creditNotePrefix;
    }

    public function setCreditNotePrefix(?string $creditNotePrefix): static
    {
        $this->creditNotePrefix = $creditNotePrefix;

        return $this;
    }

    public function getCreditNoteSuffix(): ?string
    {
        return $this->creditNoteSuffix;
    }

    public function setCreditNoteSuffix(?string $creditNoteSuffix): static
    {
        $this->creditNoteSuffix = $creditNoteSuffix;

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

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
