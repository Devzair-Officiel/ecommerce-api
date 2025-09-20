<?php

namespace App\Entity\Lov;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\MappedSuperclass]
#[UniqueEntity('title', message: "Ce titre existe déjà")]
abstract class AbstractLov
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['default_lov'])]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['default_lov', "article_detail", 'laboratory_detail', 'denomination_list', 'analyse_list'])]
    protected ?string $title = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['default_lov'])]
    protected ?int $level = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): self
    {
        $this->level = $level;
        return $this;
    }
}
