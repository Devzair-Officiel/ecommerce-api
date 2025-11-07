<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;

#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success', method: 'onAuthenticationSuccess')]
final class AuthenticationSuccessListener
{
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data         = $event->getData();
        $token        = (string)($data['token'] ?? '');
        $refreshToken = (string)($data['refresh_token'] ?? '');
        $response     = $event->getResponse();

        // En dev SI tu utilises le proxy Nuxt (même origin) : Lax + non-secure OK
        $response->headers->setCookie(
            Cookie::create('token')
                ->withValue($token)
                ->withHttpOnly(true)
                ->withSecure(false)
                ->withSameSite('lax')
                ->withPath('/')
                ->withExpires(time() + 3600)
        );

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

        // On NETTOIE le JSON (cookies only)
        unset($data['token'], $data['refresh_token']);
        $event->setData($data);
    }
}
