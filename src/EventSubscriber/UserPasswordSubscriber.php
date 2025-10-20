<?php
// src/EventSubscriber/UserPasswordSubscriber.php
declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hash le plainPassword à la création d'un User.
 * (On ne s’appuie pas dessus pour le changement de MDP.)
 */
#[AsDoctrineListener(event: Events::prePersist)]
class UserPasswordSubscriber
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof User) {
            return;
        }

        $plain = $entity->getPlainPassword();
        if (!$plain) {
            return;
        }

        $hash = $this->passwordHasher->hashPassword($entity, $plain);
        $entity->setPassword($hash);
        $entity->eraseCredentials();
    }
}
