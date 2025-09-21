<?php

/**
 * Pour un e-commerce, les clients ont besoin de plusieurs adresses : livraison, facturation, parfois différentes selon les commandes.
 * Address sépare cette logique de User pour plus de flexibilité.
 */

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: "App\Repository\AddressRepository")]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['user_id', 'type'], name: 'idx_address_user_type')]
#[ORM\Index(columns: ['user_id', 'is_default'], name: 'idx_address_user_default')]
class Address
{
    use DateTrait;

    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['address_list', 'address_detail', 'user_detail', 'order_detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "validation.address.user.required")]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Groups(['address_list', 'address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.type.required")]
    #[Assert\Choice(choices: [self::TYPE_BILLING, self::TYPE_SHIPPING], message: "validation.address.type.invalid")]
    private ?string $type = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\Length(max: 100, maxMessage: "validation.address.company.max_length")]
    private ?string $company = null;

    #[ORM\Column(length: 100)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.first_name.required")]
    #[Assert\Length(max: 100, maxMessage: "validation.address.first_name.max_length")]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.last_name.required")]
    #[Assert\Length(max: 100, maxMessage: "validation.address.last_name.max_length")]
    private ?string $lastName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.street.required")]
    #[Assert\Length(max: 255, maxMessage: "validation.address.street.max_length")]
    private ?string $street = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\Length(max: 100, maxMessage: "validation.address.street_complement.max_length")]
    private ?string $streetComplement = null;

    #[ORM\Column(length: 10)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.zip_code.required")]
    #[Assert\Length(max: 10, maxMessage: "validation.address.zip_code.max_length")]
    #[Assert\Regex(pattern: "/^[0-9A-Z\s\-]+$/i", message: "validation.address.zip_code.format")]
    private ?string $zipCode = null;

    #[ORM\Column(length: 100)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.city.required")]
    #[Assert\Length(max: 100, maxMessage: "validation.address.city.max_length")]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\Length(max: 100, maxMessage: "validation.address.state.max_length")]
    private ?string $state = null;

    #[ORM\Column(length: 3)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\NotBlank(message: "validation.address.country.required")]
    #[Assert\Country(message: "validation.address.country.invalid")]
    private ?string $country = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    #[Assert\Length(max: 20, maxMessage: "validation.address.phone.max_length")]
    #[Assert\Regex(pattern: "/^[\+]?[0-9\s\-\(\)\.]+$/", message: "validation.address.phone.format")]
    private ?string $phone = null;

    #[ORM\Column]
    #[Groups(['address_list', 'admin_read'])]
    private bool $isDefault = false;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['address_detail', 'admin_read'])]
    #[Assert\Length(max: 500, maxMessage: "validation.address.notes.max_length")]
    private ?string $notes = null;

    // === GETTERS/SETTERS BASIQUES ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    // Définit le type d'adresse avec validation
    public function setType(?string $type): static
    {
        if ($type && !in_array($type, [self::TYPE_BILLING, self::TYPE_SHIPPING])) {
            throw new \InvalidArgumentException('validation.address.type.invalid_value');
        }
        $this->type = $type;
        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    // Stocke le nom de l'entreprise (optionnel)
    public function setCompany(?string $company): static
    {
        $this->company = $company ? trim($company) : null;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    // Nettoie et stocke le prénom
    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName ? trim($firstName) : null;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    // Nettoie et stocke le nom de famille
    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName ? trim($lastName) : null;
        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    // Nettoie et stocke l'adresse principale
    public function setStreet(?string $street): static
    {
        $this->street = $street ? trim($street) : null;
        return $this;
    }

    public function getStreetComplement(): ?string
    {
        return $this->streetComplement;
    }

    // Stocke le complément d'adresse (appartement, étage, etc.)
    public function setStreetComplement(?string $streetComplement): static
    {
        $this->streetComplement = $streetComplement ? trim($streetComplement) : null;
        return $this;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    // Formate et stocke le code postal
    public function setZipCode(?string $zipCode): static
    {
        $this->zipCode = $zipCode ? strtoupper(trim($zipCode)) : null;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    // Nettoie et stocke la ville
    public function setCity(?string $city): static
    {
        $this->city = $city ? trim($city) : null;
        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    // Stocke l'état/région (pour certains pays)
    public function setState(?string $state): static
    {
        $this->state = $state ? trim($state) : null;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    // Stocke le code pays ISO (FR, US, etc.)
    public function setCountry(?string $country): static
    {
        $this->country = $country ? strtoupper(trim($country)) : null;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    // Nettoie et stocke le numéro de téléphone
    public function setPhone(?string $phone): static
    {
        $this->phone = $phone ? trim($phone) : null;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    // Stocke des notes de livraison (digicode, instructions, etc.)
    public function setNotes(?string $notes): static
    {
        $this->notes = $notes ? trim($notes) : null;
        return $this;
    }

    // === MÉTHODES D'ÉTAT SIMPLE (conformes SOLID - lecture seule de propriétés) ===

    // Vérifie si c'est une adresse de facturation
    #[Groups(['address_detail'])]
    public function isBillingAddress(): bool
    {
        return $this->type === self::TYPE_BILLING;
    }

    // Vérifie si c'est une adresse de livraison
    #[Groups(['address_detail'])]
    public function isShippingAddress(): bool
    {
        return $this->type === self::TYPE_SHIPPING;
    }

    // Vérifie si l'adresse a un nom d'entreprise
    #[Groups(['address_detail'])]
    public function hasCompany(): bool
    {
        return !empty($this->company);
    }

    // Vérifie si l'adresse a un complément
    #[Groups(['address_detail'])]
    public function hasStreetComplement(): bool
    {
        return !empty($this->streetComplement);
    }

    // Vérifie si l'adresse a un état/région
    #[Groups(['address_detail'])]
    public function hasState(): bool
    {
        return !empty($this->state);
    }

    // Vérifie si l'adresse a un téléphone
    #[Groups(['address_detail'])]
    public function hasPhone(): bool
    {
        return !empty($this->phone);
    }

    // Vérifie si l'adresse a des notes
    #[Groups(['address_detail'])]
    public function hasNotes(): bool
    {
        return !empty($this->notes);
    }

    // === MÉTHODES DE FORMATAGE SIMPLE ===

    // Retourne le nom complet (prénom + nom)
    #[Groups(['address_detail', 'user_detail', 'order_detail'])]
    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    // Retourne l'adresse complète sur une ligne
    #[Groups(['address_detail', 'order_detail'])]
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->street,
            $this->streetComplement,
            $this->zipCode . ' ' . $this->city,
            $this->state,
            $this->country
        ]);

        return implode(', ', $parts);
    }

    // Retourne l'adresse formatée pour affichage (multilignes)
    #[Groups(['address_detail', 'order_detail'])]
    public function getFormattedAddress(): array
    {
        $lines = [];

        // Nom et entreprise
        if ($this->hasCompany()) {
            $lines[] = $this->company;
        }
        $lines[] = $this->getFullName();

        // Adresse
        $lines[] = $this->street;
        if ($this->hasStreetComplement()) {
            $lines[] = $this->streetComplement;
        }

        // Ville et code postal
        $cityLine = $this->zipCode . ' ' . $this->city;
        if ($this->hasState()) {
            $cityLine .= ', ' . $this->state;
        }
        $lines[] = $cityLine;

        // Pays
        $lines[] = $this->country;

        return array_filter($lines);
    }

    // Retourne une version courte pour les listes
    #[Groups(['address_list'])]
    public function getShortAddress(): string
    {
        return $this->street . ', ' . $this->zipCode . ' ' . $this->city;
    }

    // === MÉTHODES D'ACTION MÉTIER (conformes SOLID - actions simples sur l'état) ===

    // Définit cette adresse comme par défaut
    public function setAsDefault(): static
    {
        $this->isDefault = true;
        return $this;
    }

    // Retire le statut par défaut
    public function removeDefault(): static
    {
        $this->isDefault = false;
        return $this;
    }

    // Convertit en adresse de facturation
    public function convertToBilling(): static
    {
        $this->type = self::TYPE_BILLING;
        return $this;
    }

    // Convertit en adresse de livraison
    public function convertToShipping(): static
    {
        $this->type = self::TYPE_SHIPPING;
        return $this;
    }

    // Copie les données d'une autre adresse (pour dupliquer)
    public function copyFrom(Address $address): static
    {
        $this->company = $address->getCompany();
        $this->firstName = $address->getFirstName();
        $this->lastName = $address->getLastName();
        $this->street = $address->getStreet();
        $this->streetComplement = $address->getStreetComplement();
        $this->zipCode = $address->getZipCode();
        $this->city = $address->getCity();
        $this->state = $address->getState();
        $this->country = $address->getCountry();
        $this->phone = $address->getPhone();
        $this->notes = $address->getNotes();
        return $this;
    }

    // Efface toutes les données sensibles (RGPD)
    public function anonymize(): static
    {
        $this->company = null;
        $this->firstName = 'Utilisateur';
        $this->lastName = 'Anonyme';
        $this->street = 'Adresse supprimée';
        $this->streetComplement = null;
        $this->phone = null;
        $this->notes = null;
        return $this;
    }
}
