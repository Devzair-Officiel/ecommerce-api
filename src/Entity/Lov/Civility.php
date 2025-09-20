<?php 

declare(strict_types=1);

namespace App\Entity\Lov;

use App\Entity\Lov\AbstractLov;
use App\Repository\Lov\CivilityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CivilityRepository::class)]
class Civility extends AbstractLov
{
    
}