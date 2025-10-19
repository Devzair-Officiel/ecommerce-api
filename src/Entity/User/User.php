<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Enum\User\UserRole;
use App\Repository\User\UserRepository;
use App\Traits\ActiveStateTrait;
use App\Traits\DateTrait;
use App\Traits\SiteAwareTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * User : Gère l'authentification et les comptes utilisateurs.
 * 
 * Responsabilités :
 * - Authentification JWT (email/password)
 * - Gestion des rôles et permissions
 * - Multi-tenant (isolation par site)
 * - États : actif/inactif (banni), supprimé (soft delete)
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(
    fields: ['email', 'site'],
    message: 'Cet email est déjà utilisé sur ce site.',
    errorPath: 'email'
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use DateTrait;
    use SiteAwareTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['user:read', 'user:list'])]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
    #[Assert\Email(message: 'L\'email doit être valide.')]
    #[Assert\Length(max: 180)]
    #[Groups(['user:read', 'user:list', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:list', 'user:write'])]
    private ?string $username = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:list', 'user:write'])]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:list', 'user:write'])]
    private ?string $lastName = null;

    /**
     * Mot de passe en clair (NON PERSISTÉ).
     * Utilisé uniquement pour la saisie lors inscription/changement.
     * Hashé automatiquement par UserPasswordSubscriber avant persistance.
     */
    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.', groups: ['user:create'])]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
        groups: ['user:create', 'user:password']
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/',
        message: 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
        groups: ['user:create', 'user:password']
    )]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    /**
     * Mot de passe hashé (JAMAIS exposé dans les réponses API).
     */
    #[ORM\Column(type: 'string')]
    private ?string $password = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['user:read:admin'])]
    private array $roles = [];

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^[\d\s\+\-\(\)]+$/',
        message: 'Le numéro de téléphone contient des caractères invalides.'
    )]
    #[Groups(['user:read', 'user:list', 'user:write'])]
    private ?string $phone = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['user:read:admin'])]
    private bool $isVerified = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['user:read', 'user:write'])]
    private bool $newsletterOptIn = false;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['user:read:admin'])]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['user:read', 'user:write'])]
    private ?array $metadata = null;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        $parts = array_filter([$this->firstName, $this->lastName]);
        return !empty($parts) ? implode(' ', $parts) : $this->username ?? $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = UserRole::ROLE_USER->value;
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function addRole(UserRole $role): static
    {
        if (!in_array($role->value, $this->roles, true)) {
            $this->roles[] = $role->value;
        }
        return $this;
    }

    public function removeRole(UserRole $role): static
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            fn($r) => $r !== $role->value
        ));
        return $this;
    }

    public function hasRole(UserRole $role): bool
    {
        return in_array($role->value, $this->getRoles(), true);
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

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function isNewsletterOptIn(): bool
    {
        return $this->newsletterOptIn;
    }

    public function setNewsletterOptIn(bool $newsletterOptIn): static
    {
        $this->newsletterOptIn = $newsletterOptIn;
        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): static
    {
        $this->verificationToken = $verificationToken;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    // ===============================================
    // INTERFACE UserInterface (Symfony Security)
    // ===============================================

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    #[ORM\PrePersist]
    public function generateUsername(): void
    {
        if (empty($this->username) && !empty($this->email)) {
            $this->username = explode('@', $this->email)[0];
        }
    }

    public function isAdmin(): bool
    {
        foreach ($this->getRoles() as $role) {
            $roleEnum = UserRole::tryFrom($role);
            if ($roleEnum && $roleEnum->isAdmin()) {
                return true;
            }
        }
        return false;
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin() && $this->isActive() && $this->isVerified() && !$this->isDeleted();
    }

    public function isAccountActive(): bool
    {
        return $this->isActive() && !$this->isDeleted() && $this->isVerified();
    }

    public function getAge(): ?int
    {
        if (!$this->birthDate) {
            return null;
        }
        return $this->birthDate->diff(new \DateTimeImmutable())->y;
    }

    public function updateLastLogin(): static
    {
        $this->lastLoginAt = new \DateTimeImmutable();
        return $this;
    }

    public function ban(): static
    {
        $this->deactivate();
        return $this;
    }

    public function unban(): static
    {
        $this->activate();
        return $this;
    }
}
