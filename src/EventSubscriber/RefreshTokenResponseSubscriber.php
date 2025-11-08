<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Intercepte la réponse de /token/refresh pour mettre les nouveaux tokens en cookies.
 * 
 * Gesdinet retourne les tokens en JSON, on les met aussi en cookies pour le frontend.
 */
final class RefreshTokenResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request  = $event->getRequest();
        $response = $event->getResponse();

        // Uniquement sur /token/refresh
        if ($request->getPathInfo() !== '/api/v1/token/refresh') {
            return;
        }

        // Lire le JSON de la réponse
        $content = $response->getContent();
        if (!$content) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        // Récupérer les nouveaux tokens
        $token        = (string)($data['token'] ?? '');
        $refreshToken = (string)($data['refresh_token'] ?? '');

        // Mettre le nouveau token JWT en cookie
        if ($token !== '') {
            $response->headers->setCookie(
                Cookie::create('token')
                    ->withValue($token)
                    ->withHttpOnly(true)
                    ->withSecure(false) // true en production avec HTTPS
                    ->withSameSite('lax')
                    ->withPath('/')
                    ->withExpires(time() + 3600) // 1 heure
            );
        }

        // Mettre le nouveau refresh token en cookie
        if ($refreshToken !== '') {
            $response->headers->setCookie(
                Cookie::create('refresh_token')
                    ->withValue($refreshToken)
                    ->withHttpOnly(true)
                    ->withSecure(false) // true en production avec HTTPS
                    ->withSameSite('lax')
                    ->withPath('/')
                    ->withExpires(time() + 7 * 24 * 3600) // 7 jours
            );
        }

        // Nettoyer la réponse JSON (optionnel)
        unset($data['token'], $data['refresh_token']);
        $response->setContent(json_encode($data));
    }
}
