<?php

declare(strict_types=1);

namespace App\Entity\Site;

use App\Enum\Site\SiteStatus;
use App\Repository\Site\SiteRepository;
use App\Traits\ActiveStateTrait;
use App\Traits\DateTrait;
use App\Traits\SoftDeletableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\Ignore;

/**
 * Site : Gère le multi-tenant (plusieurs boutiques sur une infrastructure).
 * 
 * Responsabilités :
 * - Configuration de la boutique (nom, domaine, devise, langues)
 * - Isolation des données entre sites
 * - Gestion du statut et de l'état actif
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Ce code site est déjà utilisé.')]
#[UniqueEntity(fields: ['domain'], message: 'Ce domaine est déjà utilisé.')]
class Site
{
    use DateTrait;
    use ActiveStateTrait;
    use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['site:read'])]
    private ?int $id = null;

    /**
     * Code unique du site (ex: FR, BE, PRO).
     * Usage interne pour identifier le site.
     */
    #[ORM\Column(type: 'string', length: 10, unique: true)]
    #[Assert\NotBlank(message: 'Le code est obligatoire.')]
    #[Assert\Length(max: 10)]
    #[Assert\Regex(pattern: '/^[A-Z_]+$/', message: 'Le code doit contenir uniquement des majuscules et underscores.')]
    #[Groups(['site:read', 'site:write', 'user:list', 'category:read'])]
    private ?string $code = null;

    /**
     * Nom commercial du site.
     */
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[Groups(['site:read', 'site:write'])]
    private ?string $name = null;

    /**
     * Domaine principal (ex: boutique.fr, shop.be).
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Le domaine est obligatoire.')]
    #[Assert\Regex(pattern: '/^[a-z0-9.-]+\.[a-z]{2,}$/i', message: 'Format de domaine invalide.')]
    #[Groups(['site:read', 'site:write'])]
    private ?string $domain = null;

    /**
     * Devise par défaut (ISO 4217 : EUR, USD, GBP).
     */
    #[ORM\Column(type: 'string', length: 3)]
    #[Assert\NotBlank(message: 'La devise est obligatoire.')]
    #[Assert\Currency]
    #[Groups(['site:read', 'site:write'])]
    private string $currency = 'EUR';

    /**
     * Langues disponibles (JSON: ["fr", "en", "es"]).
     */
    #[ORM\Column(type: 'json')]
    #[Assert\NotBlank(message: 'Au moins une langue est requise.')]
    #[Assert\Count(min: 1)]
    #[Groups(['site:read', 'site:write'])]
    private array $locales = ['fr'];

    /**
     * Langue par défaut.
     */
    #[ORM\Column(type: 'string', length: 5)]
    #[Assert\NotBlank(message: 'La langue par défaut est obligatoire.')]
    #[Assert\Locale]
    #[Groups(['site:read', 'site:write'])]
    private string $defaultLocale = 'fr';

    /**
     * Statut du site (actif, maintenance, archivé).
     */
    #[ORM\Column(type: 'string', enumType: SiteStatus::class)]
    #[Groups(['site:read', 'site:write'])]
    private SiteStatus $status = SiteStatus::ACTIVE;

    /**
     * Configuration JSON (thème, SEO, contact, social...).
     * Structure flexible pour éviter l'over-engineering.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['site:read', 'site:write'])]
    private ?array $settings = null;

    // ===============================================
    // GETTERS & SETTERS
    // ===============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = strtolower($domain);
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getLocales(): array
    {
        return $this->locales;
    }

    public function setLocales(array $locales): static
    {
        $this->locales = $locales;
        return $this;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function setDefaultLocale(string $defaultLocale): static
    {
        $this->defaultLocale = $defaultLocale;
        return $this;
    }

    public function getStatus(): SiteStatus
    {
        return $this->status;
    }

    public function setStatus(SiteStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    // ===============================================
    // HELPERS MÉTIER
    // ===============================================

    /**
     * Vérifie si le site est accessible publiquement.
     */
    public function isAccessible(): bool
    {
        return $this->status === SiteStatus::ACTIVE
            && $this->isActive()
            && !$this->isDeleted();
    }

    /**
     * Vérifie si une locale est supportée.
     */
    public function supportsLocale(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    /**
     * Récupère une valeur de configuration.
     */
    public function getSettingValue(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Définit une valeur de configuration.
     */
    #[Ignore]
    public function setSettingValue(string $key, mixed $value): static
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
        return $this;
    }

    /**
     * Ajoute une locale si elle n'existe pas déjà.
     */
    public function addLocale(string $locale): static
    {
        if (!in_array($locale, $this->locales, true)) {
            $this->locales[] = $locale;
        }
        return $this;
    }

    /**
     * Retire une locale (sauf si c'est la locale par défaut).
     */
    public function removeLocale(string $locale): static
    {
        if ($locale === $this->defaultLocale) {
            throw new \LogicException('Impossible de supprimer la locale par défaut.');
        }

        $this->locales = array_values(array_filter(
            $this->locales,
            fn($l) => $l !== $locale
        ));

        return $this;
    }
}
