<?php

namespace App\Entity;

use App\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur par site et par type (facture / avoir). L'attribution du
 * prochain numéro doit toujours passer par un verrou pessimiste
 * (voir Service\Billing\NumberingService) pour rester correcte sous accès
 * concurrent - ne jamais lire/incrémenter ce compteur sans transaction.
 */
#[ORM\Entity(repositoryClass: InvoiceSequenceRepository::class)]
#[ORM\Table(name: 'invoice_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_sequence_site_type', columns: ['site_id', 'type'])]
class InvoiceSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Site $site;

    #[ORM\Column(length: 20, enumType: SequenceType::class)]
    private SequenceType $type;

    #[ORM\Column]
    private int $nextNumber = 1;

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

    public function getType(): SequenceType
    {
        return $this->type;
    }

    public function setType(SequenceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNextNumber(): int
    {
        return $this->nextNumber;
    }

    public function setNextNumber(int $nextNumber): static
    {
        $this->nextNumber = $nextNumber;

        return $this;
    }
}
