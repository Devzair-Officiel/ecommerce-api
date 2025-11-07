<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RefreshTokenCookieSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 100]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $req = $event->getRequest();

        if ($req->getPathInfo() === '/api/v1/token/refresh' && $req->isMethod('POST')) {
            if (!$req->request->has('refresh_token')) {
                $cookie = (string) $req->cookies->get('refresh_token', '');
                if ($cookie !== '') {
                    $req->request->set('refresh_token', $cookie);
                }
            }
        }
    }
}
