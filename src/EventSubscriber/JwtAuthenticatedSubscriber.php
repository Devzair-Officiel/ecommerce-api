<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entity\User\User;

final class JwtAuthenticatedSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public static function getSubscribedEvents(): array
    {
        return [
            JWTAuthenticatedEvent::class => 'onJwtAuthenticated',
        ];
    }

    public function onJwtAuthenticated(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request) {
            $request->attributes->set('current_user', $user);
        }
    }
}
