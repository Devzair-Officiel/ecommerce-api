<?php 

namespace App\Traits;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;


trait UserAttributableTrait
{

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[Groups(['getUser'])]
    private $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[Groups(['getUser'])]
    private $updatedBy;

    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy)
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy)
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}