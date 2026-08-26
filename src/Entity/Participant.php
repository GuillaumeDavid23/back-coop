<?php

namespace App\Entity;

use App\Repository\ParticipantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'participant')]
class Participant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Registration::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false)]
    private Registration $registration;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $civility = null;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $status = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    /** Motif de l'inscription / attentes - présent dans quasiment tous les formulaires d'inscription CLCOM. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motivation = null;

    /** Besoins spécifiques (accessibilité, régime alimentaire, aménagement...). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $specialNeeds = null;

    /** Acceptation CGV / consentement - case à cocher quasi systématique. */
    #[ORM\Column]
    private bool $consentAccepted = false;

    /** Réponses libres propres à ce participant et spécifiques à l'événement (ex: présence à un cocktail). */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCivility(): ?string
    {
        return $this->civility;
    }

    public function setCivility(?string $civility): static
    {
        $this->civility = $civility;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getMotivation(): ?string
    {
        return $this->motivation;
    }

    public function setMotivation(?string $motivation): static
    {
        $this->motivation = $motivation;

        return $this;
    }

    public function getSpecialNeeds(): ?string
    {
        return $this->specialNeeds;
    }

    public function setSpecialNeeds(?string $specialNeeds): static
    {
        $this->specialNeeds = $specialNeeds;

        return $this;
    }

    public function isConsentAccepted(): bool
    {
        return $this->consentAccepted;
    }

    public function setConsentAccepted(bool $consentAccepted): static
    {
        $this->consentAccepted = $consentAccepted;

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

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
