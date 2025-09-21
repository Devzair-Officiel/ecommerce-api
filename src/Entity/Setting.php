<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Index(columns: ['site_id', 'key'], name: 'idx_setting_site_key')]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['setting_list', 'admin_read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Site $site = null;

    #[ORM\Column(length: 100)]
    #[Groups(['setting_list', 'setting_detail', 'admin_read'])]
    private ?string $key = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['setting_detail', 'public_read'])]
    private ?string $value = null;

    #[ORM\Column(length: 50)]
    #[Groups(['setting_detail', 'admin_read'])]
    private string $type = 'text'; // text, json, boolean, number

    #[ORM\Column(length: 200, nullable: true)]
    #[Groups(['setting_detail', 'admin_read'])]
    private ?string $description = null;

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
    public function getKey(): ?string
    {
        return $this->key;
    }
    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }
    public function getValue(): ?string
    {
        return $this->value;
    }
    public function setValue(?string $value): static
    {
        $this->value = $value;
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
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    #[Groups(['setting_detail', 'public_read'])]
    public function getParsedValue(): mixed
    {
        return match ($this->type) {
            'json' => json_decode($this->value, true),
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($this->value) ? (float) $this->value : $this->value,
            default => $this->value
        };
    }
}