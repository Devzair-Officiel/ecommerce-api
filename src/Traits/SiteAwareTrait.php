<?php

declare(strict_types=1);

namespace App\Traits;

use App\Entity\Site\Site;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Trait pour les entités liées à un site (multi-tenant).
 * 
 * Usage : Isolation automatique des données par site.
 * 
 * Exemples :
 * - User appartient à UN site (compte séparé par site)
 * - Product appartient à UN site (catalogue isolé)
 * - Order appartient à UN site (commandes isolées)
 * 
 * ⚠️ Filtre Doctrine automatique recommandé pour isolation transparente.
 */
trait SiteAwareTrait
{
    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['site'])]
    private ?Site $site = null;

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    /**
     * Vérifie si l'entité appartient à un site donné.
     */
    public function belongsToSite(Site $site): bool
    {
        return $this->site !== null && $this->site->getId() === $site->getId();
    }
}
