<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Enum\User\AddressType;
use App\Repository\User\AddressRepository;
use App\Traits\DateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Address : Adresses de livraison et facturation.
 * 
 * Responsabilités :
 * - Stockage des coordonnées complètes
 * - Réutilisation pour plusieurs commandes
 * - Validation selon le pays (code postal, format...)
 * - Adresse par défaut par utilisateur
 * 
 * Relations :
 * - ManyToOne avec User (un utilisateur a plusieurs adresses)
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Address
{
    use DateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['address:read', 'address:list'])]
    private ?int $id = null;

    /**
     * Utilisateur propriétaire de l'adresse.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['address:read:admin'])]
    private ?User $user = null;

    /**
     * Type d'adresse (facturation, livraison, les deux).
     */
    #[ORM\Column(type: 'string', enumType: AddressType::class)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private AddressType $type = AddressType::BOTH;

    /**
     * Nom complet du destinataire.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom complet est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $fullName = null;

    /**
     * Société (optionnel, pour adresses professionnelles).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $company = null;

    /**
     * Numéro et nom de rue.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'La rue est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $street = null;

    /**
     * Complément d'adresse (bâtiment, appartement, etc.).
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $additionalAddress = null;

    /**
     * Code postal.
     */
    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank(message: 'Le code postal est obligatoire.')]
    #[Assert\Length(max: 20)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $postalCode = null;

    /**
     * Ville.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $city = null;

    /**
     * Code pays ISO 3166-1 alpha-2 (FR, BE, CH, etc.).
     */
    #[ORM\Column(type: 'string', length: 2)]
    #[Assert\NotBlank(message: 'Le pays est obligatoire.')]
    #[Assert\Country]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private string $countryCode = 'FR';

    /**
     * Téléphone de contact.
     */
    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank(message: 'Le téléphone est obligatoire.')]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^[\d\s\+\-\(\)]+$/',
        message: 'Le numéro de téléphone contient des caractères invalides.'
    )]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $phone = null;

    /**
     * Adresse par défaut de l'utilisateur.
     * Un seul true par utilisateur.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private bool $isDefault = false;

    /**
     * Label personnalisé (ex: "Maison", "Bureau", "Parents").
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['address:read', 'address:list', 'address:write'])]
    private ?string $label = null;

    /**
     * Instructions de livraison (code portail, étage...).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    #[Groups(['address:read', 'address:write'])]
    private ?string $deliveryInstructions = null;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): AddressType
    {
        return $this->type;
    }

    public function setType(AddressType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;
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

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;
        return $this;
    }

    public function getAdditionalAddress(): ?string
    {
        return $this->additionalAddress;
    }

    public function setAdditionalAddress(?string $additionalAddress): static
    {
        $this->additionalAddress = $additionalAddress;
        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): static
    {
        $this->countryCode = strtoupper($countryCode);
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getDeliveryInstructions(): ?string
    {
        return $this->deliveryInstructions;
    }

    public function setDeliveryInstructions(?string $deliveryInstructions): static
    {
        $this->deliveryInstructions = $deliveryInstructions;
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Retourne l'adresse formatée sur plusieurs lignes.
     */
    public function getFormattedAddress(): string
    {
        $lines = [];

        if ($this->fullName) {
            $lines[] = $this->fullName;
        }

        if ($this->company) {
            $lines[] = $this->company;
        }

        if ($this->street) {
            $lines[] = $this->street;
        }

        if ($this->additionalAddress) {
            $lines[] = $this->additionalAddress;
        }

        if ($this->postalCode && $this->city) {
            $lines[] = $this->postalCode . ' ' . $this->city;
        }

        if ($this->countryCode) {
            $lines[] = strtoupper($this->countryCode);
        }

        return implode("\n", $lines);
    }

    /**
     * Retourne l'adresse formatée sur une seule ligne.
     */
    public function getFormattedAddressOneLine(): string
    {
        $parts = array_filter([
            $this->street,
            $this->additionalAddress,
            $this->postalCode,
            $this->city,
            strtoupper($this->countryCode)
        ]);

        return implode(', ', $parts);
    }

    /**
     * Vérifie si l'adresse peut être utilisée pour la facturation.
     */
    public function canBeUsedForBilling(): bool
    {
        return $this->type->canBeUsedForBilling();
    }

    /**
     * Vérifie si l'adresse peut être utilisée pour la livraison.
     */
    public function canBeUsedForShipping(): bool
    {
        return $this->type->canBeUsedForShipping();
    }

    /**
     * Retourne le nom du pays (nécessite extension intl PHP).
     */
    public function getCountryName(string $locale = 'fr'): string
    {
        return \Locale::getDisplayRegion('-' . $this->countryCode, $locale);
    }

    /**
     * Clone l'adresse (pour snapshot dans Order).
     */
    public function toArray(): array
    {
        return [
            'fullName' => $this->fullName,
            'company' => $this->company,
            'street' => $this->street,
            'additionalAddress' => $this->additionalAddress,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'countryCode' => $this->countryCode,
            'phone' => $this->phone,
            'deliveryInstructions' => $this->deliveryInstructions,
        ];
    }
}
