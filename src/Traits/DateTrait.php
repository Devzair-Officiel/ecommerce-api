<?php

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Annotation\Context;

#[ORM\HasLifecycleCallbacks]
trait DateTrait
{

    #[ORM\Column(type: 'datetime_immutable')]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'd/m/Y'])]
    #[Groups(['date'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'd/m/Y'])]
    #[Groups(['date'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Déclenché juste avant qu’une entité soit insérée en BDD
     * @return void
     */
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!$this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Déclenché avant une mise à jour
     * @return void
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

}
