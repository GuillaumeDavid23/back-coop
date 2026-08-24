<?php

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
#[ORM\UniqueConstraint(name: 'uniq_invoice_number', columns: ['number'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: Registration::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Registration $registration;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Payment $payment = null;

    /** Numéro formaté final, ex: "ED26-000001-CLCOM". */
    #[ORM\Column(length: 100)]
    private string $number;

    /** Valeur brute du compteur avant préfixe/suffixe, ex: 1. */
    #[ORM\Column]
    private int $sequenceNumber;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amountExclTax;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $taxAmount;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amountInclTax;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    /**
     * Copie figée des données de facturation (nom, adresse, cabinet...) au
     * moment de l'émission — ne JAMAIS relire les infos actuelles du
     * participant/site pour régénérer une facture déjà émise.
     */
    #[ORM\Column(type: Types::JSON)]
    private array $billingDataSnapshot = [];

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

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function setSequenceNumber(int $sequenceNumber): static
    {
        $this->sequenceNumber = $sequenceNumber;

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

    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(string $taxAmount): static
    {
        $this->taxAmount = $taxAmount;

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

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;

        return $this;
    }

    public function getBillingDataSnapshot(): array
    {
        return $this->billingDataSnapshot;
    }

    public function setBillingDataSnapshot(array $billingDataSnapshot): static
    {
        $this->billingDataSnapshot = $billingDataSnapshot;

        return $this;
    }

    public function __toString(): string
    {
        return $this->number;
    }
}
