<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber pour gérer la sélection de la langue dans l'application.
 * 
 * - Définit la locale à partir du paramètre `lang` ou de l'en-tête `Accept-Language`.
 * - Applique la locale sélectionnée au service de traduction.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    /**
     * @param LocaleAwareInterface $translator Service de traduction.
     * @param string $defaultLocale Langue par défaut (défaut: "en").
     */
    public function __construct(
        private LocaleAwareInterface $translator,
        private string $defaultLocale = 'fr'
    ) {}

    /**
     * Définit la locale à utiliser en fonction de la requête HTTP.
     *
     * Priorité de sélection :
     * 1. Si le paramètre `lang` est présent dans l’URL, il est utilisé.
     * 2. Sinon, la locale est déterminée à partir de l'en-tête `Accept-Language`, si elle est supportée.
     * 3. Si aucune langue valide n’est trouvée, la locale par défaut est utilisée.
     *
     * @param RequestEvent $event L'événement contenant la requête HTTP.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Étape 1 : tenter de lire le paramètre `lang`
        $langParam = $request->query->get('lang');

        if ($langParam) {
            $locale = $langParam;
        } else {
            // Étape 2 : lire l'en-tête Accept-Language uniquement si le paramètre `lang` est absent
            $preferred = $request->getPreferredLanguage(['fr', 'en']);
            $locale = $preferred ?: $this->defaultLocale;
        }

        // Étape 3 : appliquer la locale sélectionnée
        $request->setLocale($locale);
        $this->translator->setLocale($locale);
    }


    /**
     * Définit les événements écoutés par le subscriber.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
