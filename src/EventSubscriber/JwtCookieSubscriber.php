<?php
// src/EventSubscriber/JwtCookieSubscriber.php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Intercepte les réponses de login et refresh pour mettre les tokens en cookies
 */
final class JwtCookieSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10], // Priorité basse pour s'exécuter après Gesdinet
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request  = $event->getRequest();
        $response = $event->getResponse();

        $routeName = (string) $request->attributes->get('_route');
        $handledRoutes = [
            'api_auth_register',            // Controller d'inscription
            'api_login_check',              // Login
            'gesdinet_jwt_refresh_token'    // refresh
        ];

        if (!\in_array($routeName, $handledRoutes, true)) {
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

        // 🔎 Chercher token/refresh_token à la racine OU dans data.*
        $token        = $data['token'] ?? ($data['data']['token'] ?? null);
        $refreshToken = $data['refresh_token'] ?? ($data['data']['refresh_token'] ?? null);

        // Mettre le token JWT en cookie
        if ($token !== '') {
            $response->headers->setCookie(
                Cookie::create('token')
                    ->withValue($token)
                    ->withHttpOnly(true)
                    ->withSecure(false)
                    ->withSameSite('lax')
                    ->withPath('/')
                    ->withExpires(time() + 3600)
            );
        }

        // Mettre le refresh token en cookie
        if ($refreshToken !== '') {
            $response->headers->setCookie(
                Cookie::create('refresh_token')
                    ->withValue($refreshToken)
                    ->withHttpOnly(true)
                    ->withSecure(false)
                    ->withSameSite('lax')
                    ->withPath('/')
                    ->withExpires(time() + 7 * 24 * 3600)
            );
        }

        // Nettoyer la réponse JSON (optionnel)
        unset($data['token'], $data['refresh_token']);
        $response->setContent(json_encode($data));
    }
}
