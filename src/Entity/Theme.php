<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Theme
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['theme_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'theme', targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['theme_detail', 'public_read'])]
    private array $colors = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['theme_detail', 'public_read'])]
    private array $fonts = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['theme_detail', 'public_read'])]
    private array $layout = [];

    #[ORM\Column(type: 'json')]
    #[Groups(['theme_detail', 'admin_read'])]
    private array $customCss = [];

    #[ORM\OneToMany(mappedBy: 'theme', targetEntity: Block::class, cascade: ['persist', 'remove'])]
    #[Groups(['theme_detail'])]
    private Collection $blocks;

    public function __construct()
    {
        $this->blocks = new ArrayCollection();
        $this->colors = [
            'primary' => '#3498db',
            'secondary' => '#2ecc71',
            'accent' => '#e74c3c',
            'background' => '#ffffff',
            'text' => '#2c3e50'
        ];
    }

    // Getters/Setters...
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSite(): ?Site
    {
        return $this->site;
    }
    public function setSite(Site $site): static
    {
        $this->site = $site;
        return $this;
    }
    public function getColors(): array
    {
        return $this->colors;
    }
    public function setColors(array $colors): static
    {
        $this->colors = $colors;
        return $this;
    }
    public function getFonts(): array
    {
        return $this->fonts;
    }
    public function setFonts(array $fonts): static
    {
        $this->fonts = $fonts;
        return $this;
    }
    public function getLayout(): array
    {
        return $this->layout;
    }
    public function setLayout(array $layout): static
    {
        $this->layout = $layout;
        return $this;
    }
    public function getCustomCss(): array
    {
        return $this->customCss;
    }
    public function setCustomCss(array $customCss): static
    {
        $this->customCss = $customCss;
        return $this;
    }
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }
}
