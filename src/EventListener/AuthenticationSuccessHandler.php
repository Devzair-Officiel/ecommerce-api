<?php 

namespace App\EventListener;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class AuthenticationSuccessHandler{
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        $token = $data['token'];
        $response = $event->getResponse();

        // Ajouter un cookie sécurisé avec le token
        $cookie = Cookie::create('token')
            ->withValue($token)
            ->withHttpOnly(true)
            ->withSecure(false) // Changez en true en production
            ->withSameSite('Strict')
            ->withExpires(time() + 3600);

        $response->headers->setCookie($cookie);

        // Facultatif : supprimer le token de la réponse JSON
        unset($data['token']);
        $event->setData($data);
    }
}