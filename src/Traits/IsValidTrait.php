<?php

declare(strict_types=1);

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Trait pour la validation métier d'une entité.
 * 
 * Usage : Modération, validation par admin, conformité aux règles métier.
 * 
 * Exemples :
 * - Produit en attente de validation admin (isValid = false)
 * - Review modéré avant publication (isValid = false)
 * - Commande confirmée par paiement (isValid = true)
 * 
 * ⚠️ Ne pas confondre avec :
 * - Active/Inactive : utiliser ActiveStateTrait (closedAt)
 * - Suppression : utiliser SoftDeletableTrait (isDeleted)
 */
trait IsValidTrait
{
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['valid'])]
    private bool $isValid = true;

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function setIsValid(bool $isValid): static
    {
        $this->isValid = $isValid;
        return $this;
    }

    /**
     * Helper : Valider l'entité
     */
    public function validate(): static
    {
        return $this->setIsValid(true);
    }

    /**
     * Helper : Invalider l'entité
     */
    public function invalidate(): static
    {
        return $this->setIsValid(false);
    }
}
