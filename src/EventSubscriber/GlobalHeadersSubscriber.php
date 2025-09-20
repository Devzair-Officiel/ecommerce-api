<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber pour ajouter des en-têtes HTTP globaux à chaque réponse.
 * 
 * - Ajoute des en-têtes de sécurité pour renforcer la protection.
 * - Gère les en-têtes CORS (Cross-Origin Resource Sharing).
 */
class GlobalHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * Définit les événements écoutés.
     * 
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    /**
     * Ajoute des en-têtes de sécurité et CORS à chaque réponse HTTP.
     * 
     * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
     * @return void
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        // En-têtes de sécurité
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // En-têtes CORS
        $response->headers->set('Access-Control-Allow-Origin', '*'); // CHNAGER EN PROD POUR RESTREINDRE L'AUTORISATION ORIGIN 
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');

    }
}
