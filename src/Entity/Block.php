<?php

/**
 * Block permet de créer un CMS modulaire pour ton e-commerce.
 * Au lieu d'avoir un design figé, tu peux personnaliser header, footer, bannières depuis l'admin pour chaque site.
 */

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: "App\Repository\BlockRepository")]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['theme_id', 'type'], name: 'idx_block_theme_type')]
#[ORM\Index(columns: ['type', 'position'], name: 'idx_block_type_position')]
#[ORM\Index(columns: ['is_active'], name: 'idx_block_active')]
class Block
{
    use DateTrait;

    public const TYPE_HEADER = 'header';
    public const TYPE_FOOTER = 'footer';
    public const TYPE_MENU = 'menu';
    public const TYPE_BANNER = 'banner';
    public const TYPE_SIDEBAR = 'sidebar';
    public const TYPE_HERO = 'hero';
    public const TYPE_TESTIMONIAL = 'testimonial';
    public const TYPE_NEWSLETTER = 'newsletter';
    public const TYPE_CUSTOM = 'custom';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['block_list', 'block_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Theme::class, inversedBy: 'blocks')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "validation.block.theme.required")]
    private ?Theme $theme = null;

    #[ORM\Column(length: 50)]
    #[Groups(['block_list', 'block_detail', 'public_read'])]
    #[Assert\NotBlank(message: "validation.block.type.required")]
    #[Assert\Choice(choices: [
        self::TYPE_HEADER,
        self::TYPE_FOOTER,
        self::TYPE_MENU,
        self::TYPE_BANNER,
        self::TYPE_SIDEBAR,
        self::TYPE_HERO,
        self::TYPE_TESTIMONIAL,
        self::TYPE_NEWSLETTER,
        self::TYPE_CUSTOM
    ], message: "validation.block.type.invalid")]
    private ?string $type = null;

    #[ORM\Column(length: 100)]
    #[Groups(['block_list', 'block_detail', 'public_read'])]
    #[Assert\NotBlank(message: "validation.block.name.required")]
    #[Assert\Length(max: 100, maxMessage: "validation.block.name.max_length")]
    private ?string $name = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['block_list', 'block_detail', 'admin_read'])]
    #[Assert\NotBlank(message: "validation.block.identifier.required")]
    #[Assert\Length(max: 100, maxMessage: "validation.block.identifier.max_length")]
    #[Assert\Regex(pattern: "/^[a-z0-9_\-]+$/", message: "validation.block.identifier.format")]
    private ?string $identifier = null;

    #[ORM\Column]
    #[Groups(['block_list', 'admin_read'])]
    #[Assert\PositiveOrZero(message: "validation.block.position.positive")]
    private int $position = 0;

    #[ORM\Column]
    #[Groups(['block_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['block_list', 'admin_read'])]
    private bool $isSystem = false;

    #[ORM\Column(type: 'json')]
    #[Groups(['block_detail', 'public_read'])]
    private array $content = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['block_detail', 'admin_read'])]
    private array $settings = [];

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['block_detail', 'admin_read'])]
    #[Assert\Length(max: 1000, maxMessage: "validation.block.description.max_length")]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['block_detail', 'admin_read'])]
    #[Assert\Length(max: 50, maxMessage: "validation.block.template.max_length")]
    private ?string $template = null;

    public function __construct()
    {
        $this->content = [];
        $this->settings = [];
    }

    // === GETTERS/SETTERS BASIQUES ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTheme(): ?Theme
    {
        return $this->theme;
    }

    public function setTheme(?Theme $theme): static
    {
        $this->theme = $theme;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    // Définit le type de bloc avec validation
    public function setType(?string $type): static
    {
        $validTypes = [
            self::TYPE_HEADER,
            self::TYPE_FOOTER,
            self::TYPE_MENU,
            self::TYPE_BANNER,
            self::TYPE_SIDEBAR,
            self::TYPE_HERO,
            self::TYPE_TESTIMONIAL,
            self::TYPE_NEWSLETTER,
            self::TYPE_CUSTOM
        ];

        if ($type && !in_array($type, $validTypes)) {
            throw new \InvalidArgumentException('validation.block.type.invalid_value');
        }
        $this->type = $type;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    // Nettoie et stocke le nom du bloc
    public function setName(?string $name): static
    {
        $this->name = $name ? trim($name) : null;
        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    // Formate l'identifiant unique pour l'intégration
    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = $identifier ? strtolower(trim($identifier)) : null;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    // Définit la position d'affichage avec validation
    public function setPosition(int $position): static
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('validation.block.position.negative');
        }
        $this->position = $position;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    // Marque le bloc comme système (non supprimable)
    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getContent(): array
    {
        return $this->content;
    }

    public function setContent(array $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // Stocke une description pour l'admin
    public function setDescription(?string $description): static
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    // Définit le template Twig à utiliser
    public function setTemplate(?string $template): static
    {
        $this->template = $template ? trim($template) : null;
        return $this;
    }

    // === MÉTHODES D'ÉTAT SIMPLE (conformes SOLID - lecture seule de propriétés) ===

    // Vérifie si c'est un bloc header
    #[Groups(['block_detail'])]
    public function isHeaderBlock(): bool
    {
        return $this->type === self::TYPE_HEADER;
    }

    // Vérifie si c'est un bloc footer
    #[Groups(['block_detail'])]
    public function isFooterBlock(): bool
    {
        return $this->type === self::TYPE_FOOTER;
    }

    // Vérifie si c'est un bloc menu
    #[Groups(['block_detail'])]
    public function isMenuBlock(): bool
    {
        return $this->type === self::TYPE_MENU;
    }

    // Vérifie si c'est un bloc bannière
    #[Groups(['block_detail'])]
    public function isBannerBlock(): bool
    {
        return $this->type === self::TYPE_BANNER;
    }

    // Vérifie si c'est un bloc personnalisé
    #[Groups(['block_detail'])]
    public function isCustomBlock(): bool
    {
        return $this->type === self::TYPE_CUSTOM;
    }

    // Vérifie si le bloc a du contenu
    #[Groups(['block_detail'])]
    public function hasContent(): bool
    {
        return !empty($this->content);
    }

    // Vérifie si le bloc a des paramètres
    #[Groups(['block_detail'])]
    public function hasSettings(): bool
    {
        return !empty($this->settings);
    }

    // Vérifie si le bloc a un template personnalisé
    #[Groups(['block_detail'])]
    public function hasCustomTemplate(): bool
    {
        return !empty($this->template);
    }

    // Vérifie si le bloc peut être supprimé
    #[Groups(['block_detail'])]
    public function isDeletable(): bool
    {
        return !$this->isSystem;
    }

    // === MÉTHODES DE GESTION DU CONTENU ===

    // Récupère une valeur du contenu avec défaut
    public function getContentValue(string $key, mixed $default = null): mixed
    {
        return $this->content[$key] ?? $default;
    }

    // Définit une valeur dans le contenu
    public function setContentValue(string $key, mixed $value): static
    {
        $this->content[$key] = $value;
        return $this;
    }

    // Supprime une clé du contenu
    public function removeContentValue(string $key): static
    {
        unset($this->content[$key]);
        return $this;
    }

    // Vérifie si une clé existe dans le contenu
    public function hasContentValue(string $key): bool
    {
        return array_key_exists($key, $this->content);
    }

    // Fusionne du contenu existant
    public function mergeContent(array $content): static
    {
        $this->content = array_merge($this->content, $content);
        return $this;
    }

    // Vide tout le contenu
    public function clearContent(): static
    {
        $this->content = [];
        return $this;
    }

    // === MÉTHODES DE GESTION DES PARAMÈTRES ===

    // Récupère un paramètre avec défaut
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    // Définit un paramètre
    public function setSetting(string $key, mixed $value): static
    {
        $this->settings[$key] = $value;
        return $this;
    }

    // Supprime un paramètre
    public function removeSetting(string $key): static
    {
        unset($this->settings[$key]);
        return $this;
    }

    // Vérifie si un paramètre existe
    public function hasSetting(string $key): bool
    {
        return array_key_exists($key, $this->settings);
    }

    // Fusionne des paramètres existants
    public function mergeSettings(array $settings): static
    {
        $this->settings = array_merge($this->settings, $settings);
        return $this;
    }

    // === MÉTHODES DE FORMATAGE SIMPLE ===

    // Retourne le template à utiliser (custom ou par défaut)
    #[Groups(['block_detail'])]
    public function getEffectiveTemplate(): string
    {
        return $this->template ?: 'blocks/' . $this->type . '.html.twig';
    }

    // Retourne un nom d'affichage complet
    #[Groups(['block_list', 'block_detail'])]
    public function getDisplayName(): string
    {
        return $this->name . ' (' . ucfirst($this->type) . ')';
    }

    // Retourne un résumé du contenu pour l'admin
    #[Groups(['block_list'])]
    public function getContentSummary(): string
    {
        if (empty($this->content)) {
            return 'Aucun contenu';
        }

        $summary = [];

        // Titre
        if (isset($this->content['title'])) {
            $summary[] = 'Titre: ' . substr($this->content['title'], 0, 30) . '...';
        }

        // Texte
        if (isset($this->content['text'])) {
            $summary[] = 'Texte: ' . substr(strip_tags($this->content['text']), 0, 50) . '...';
        }

        // Images
        if (isset($this->content['image']) || isset($this->content['images'])) {
            $summary[] = 'Contient des images';
        }

        // Liens
        if (isset($this->content['links']) && is_array($this->content['links'])) {
            $summary[] = count($this->content['links']) . ' lien(s)';
        }

        return implode(' | ', $summary) ?: 'Contenu personnalisé';
    }

    // === MÉTHODES D'ACTION MÉTIER (conformes SOLID - actions simples sur l'état) ===

    // Active le bloc
    public function activate(): static
    {
        $this->isActive = true;
        return $this;
    }

    // Désactive le bloc
    public function deactivate(): static
    {
        $this->isActive = false;
        return $this;
    }

    // Marque comme bloc système
    public function markAsSystem(): static
    {
        $this->isSystem = true;
        return $this;
    }

    // Retire le statut système
    public function unmarkAsSystem(): static
    {
        $this->isSystem = false;
        return $this;
    }

    // Déplace vers le haut (diminue position)
    public function moveUp(): static
    {
        if ($this->position > 0) {
            $this->position--;
        }
        return $this;
    }

    // Déplace vers le bas (augmente position)
    public function moveDown(): static
    {
        $this->position++;
        return $this;
    }

    // Définit comme premier (position 0)
    public function moveToTop(): static
    {
        $this->position = 0;
        return $this;
    }

    // Copie le contenu d'un autre bloc
    public function copyContentFrom(Block $block): static
    {
        $this->content = $block->getContent();
        $this->settings = $block->getSettings();
        return $this;
    }

    // Duplique le bloc (nouveau nom/identifier requis)
    public function duplicate(string $newName, string $newIdentifier): static
    {
        $duplicate = clone $this;
        $duplicate->id = null; // Force un nouvel ID
        $duplicate->setName($newName);
        $duplicate->setIdentifier($newIdentifier);
        $duplicate->setIsSystem(false); // Les duplicatas ne sont jamais système
        return $duplicate;
    }

    // Reset le bloc à son état par défaut selon le type
    public function resetToDefault(): static
    {
        $this->content = $this->getDefaultContentForType();
        $this->settings = $this->getDefaultSettingsForType();
        return $this;
    }

    // === MÉTHODES PRIVÉES D'AIDE ===

    // Retourne le contenu par défaut selon le type
    private function getDefaultContentForType(): array
    {
        return match ($this->type) {
            self::TYPE_HEADER => [
                'logo' => '',
                'navigation' => []
            ],
            self::TYPE_FOOTER => [
                'copyright' => '© ' . date('Y') . ' Tous droits réservés',
                'links' => []
            ],
            self::TYPE_BANNER => [
                'title' => '',
                'text' => '',
                'background_color' => '#ffffff'
            ],
            self::TYPE_HERO => [
                'title' => '',
                'subtitle' => '',
                'button_text' => '',
                'button_url' => '',
                'background_image' => ''
            ],
            default => []
        };
    }

    // Retourne les paramètres par défaut selon le type
    private function getDefaultSettingsForType(): array
    {
        return match ($this->type) {
            self::TYPE_BANNER => [
                'auto_hide' => false,
                'hide_delay' => 5000
            ],
            self::TYPE_HERO => [
                'height' => '400px',
                'text_align' => 'center'
            ],
            default => []
        };
    }
}
