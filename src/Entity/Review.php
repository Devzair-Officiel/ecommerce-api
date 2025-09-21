<?php

namespace App\Entity;

use App\Traits\DateTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['product_id', 'is_approved'], name: 'idx_review_product_approved')]
class Review
{
    use DateTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['review_list', 'review_detail', 'product_detail'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['review_detail', 'product_detail'])]
    private ?User $user = null;

    #[ORM\Column]
    #[Groups(['review_list', 'review_detail', 'product_detail', 'public_read'])]
    private int $rating = 5; // 1 à 5 étoiles

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['review_list', 'review_detail', 'product_detail', 'public_read'])]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['review_detail', 'product_detail', 'public_read'])]
    private ?string $comment = null;

    #[ORM\Column]
    #[Groups(['review_list', 'admin_read'])]
    private bool $isApproved = false;

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getProduct(): ?Product
    {
        return $this->product;
    }
    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
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
    public function getRating(): int
    {
        return $this->rating;
    }
    public function setRating(int $rating): static
    {
        $this->rating = max(1, min(5, $rating));
        return $this;
    }
    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }
    public function getComment(): ?string
    {
        return $this->comment;
    }
    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }
    public function isApproved(): bool
    {
        return $this->isApproved;
    }
    public function setIsApproved(bool $isApproved): static
    {
        $this->isApproved = $isApproved;
        return $this;
    }
}