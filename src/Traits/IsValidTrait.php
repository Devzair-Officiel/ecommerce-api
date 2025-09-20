<?php 

namespace App\Traits;
use Symfony\Component\Serializer\Annotation\Groups;

trait IsValidTrait
{
    /**
     * Indique si l'entité est valide.
     */
    #[\Doctrine\ORM\Mapping\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['user_list'])]
    private bool $valid = true;

    /**
     * Récupère l'état de validité de l'entité.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Définit l'état de validité de l'entité.
     *
     * @param bool $valid
     * @return self
     */
    public function setValid(bool $valid): self
    {
        $this->valid = $valid;

        return $this;
    }
}
