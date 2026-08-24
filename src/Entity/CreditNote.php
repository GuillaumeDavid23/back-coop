<?php

namespace App\Entity;

use App\Repository\CreditNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditNoteRepository::class)]
#[ORM\Table(name: 'credit_note')]
#[ORM\UniqueConstraint(name: 'uniq_credit_note_number', columns: ['number'])]
class CreditNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 100)]
    private string $number;

    #[ORM\Column]
    private int $sequenceNumber;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

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

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(Invoice $invoice): static
    {
        $this->invoice = $invoice;

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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

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
}
