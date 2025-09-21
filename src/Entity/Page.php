<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['site_id', 'slug'], name: 'idx_page_site_slug')]
class Page
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['page_list', 'page_detail', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 200)]
    #[Groups(['page_list', 'page_detail', 'public_read'])]
    private ?string $title = null;

    #[ORM\Column(length: 220, unique: true)]
    #[Groups(['page_list', 'page_detail', 'public_read'])]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['page_detail', 'public_read'])]
    private ?string $content = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['page_detail', 'public_read'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['page_detail', 'public_read'])]
    private ?string $metaDescription = null;

    #[ORM\Column]
    #[Groups(['page_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column(length: 50)]
    #[Groups(['page_list', 'admin_read'])]
    private string $type = 'standard'; // standard, home, contact, about

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSite(): ?Site
    {
        return $this->site;
    }
    public function setSite(?Site $site): static
    {
        $this->site = $site;
        return $this;
    }
    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }
    public function getSlug(): ?string
    {
        return $this->slug;
    }
    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }
    public function getContent(): ?string
    {
        return $this->content;
    }
    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }
    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }
    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }
    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
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
    public function getType(): string
    {
        return $this->type;
    }
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }
}
