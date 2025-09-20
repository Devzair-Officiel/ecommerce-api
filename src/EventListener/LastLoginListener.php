<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LastLoginListener
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User) {
            $user->setLastLogin(new \DateTimeImmutable());

            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }
    }
}
