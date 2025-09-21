<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['site_id', 'email'], name: 'idx_newsletter_site_email')]
class Newsletter
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['newsletter_list', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 255)]
    #[Groups(['newsletter_list', 'admin_read'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['newsletter_list', 'admin_read'])]
    private bool $isActive = true;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['newsletter_list', 'admin_read'])]
    private ?string $firstName = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['newsletter_list', 'admin_read'])]
    private ?\DateTimeImmutable $unsubscribedAt = null;

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
    public function getEmail(): ?string
    {
        return $this->email;
    }
    public function setEmail(string $email): static
    {
        $this->email = $email;
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
    public function getFirstName(): ?string
    {
        return $this->firstName;
    }
    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }
    public function getUnsubscribedAt(): ?\DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }
    public function setUnsubscribedAt(?\DateTimeImmutable $unsubscribedAt): static
    {
        $this->unsubscribedAt = $unsubscribedAt;
        return $this;
    }

    public function unsubscribe(): static
    {
        $this->isActive = false;
        $this->unsubscribedAt = new \DateTimeImmutable();
        return $this;
    }
}