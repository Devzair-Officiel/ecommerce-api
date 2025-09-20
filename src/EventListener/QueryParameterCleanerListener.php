<?php 

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Supprime la clé 'q' et la valeur des parametre de la requete 
 * Ex : 'q' => 'api/v1/Users'
 */
class QueryParameterCleanerListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Supprimer la clé "q" des paramètres de requête
        $queryParams = $request->query->all();
        unset($queryParams['q']);

        // Remettre les paramètres nettoyés dans la requête
        $request->query->replace($queryParams);
    }
}
