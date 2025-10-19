<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Subscriber pour personnaliser le payload du JWT.
 * 
 * - Ajoute l'ID de l'utilisateur dans le token JWT.
 */
#[AsEventListener(event: JWTCreatedEvent::class, method: 'onJWTCreated')]
final class JWTEventSubscriber
{

    /**
     * Ajoute des informations supplémentaires au payload du JWT.
     *
     * @param JWTCreatedEvent $event Événement de création du JWT.
     */
    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Ajouter l'ID de l'utilisateur dans le payload
        $payload = $event->getData();
        $payload['id'] = $user->getId();
        $payload['email'] = $user->getEmail();

        $event->setData($payload);
    }
}
