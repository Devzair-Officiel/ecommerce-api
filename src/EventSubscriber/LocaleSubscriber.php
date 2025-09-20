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
    private LocaleAwareInterface $translator;
    private string $defaultLocale;

    /**
     * @param LocaleAwareInterface $translator Service de traduction.
     * @param string $defaultLocale Langue par défaut (défaut: "en").
     */
    public function __construct(LocaleAwareInterface $translator, string $defaultLocale = 'en')
    {
        $this->translator = $translator;
        $this->defaultLocale = $defaultLocale;
    }

    /**
     * Définit la locale à utiliser en fonction de la requête.
     *
     * @param RequestEvent $event L'événement contenant la requête HTTP.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Récupération de la langue depuis le paramètre `lang`
        if ($lang = $request->query->get('lang', 'fr')) {
            $request->setLocale($lang);
        }

        // Récupère la langue préférée depuis l'en-tête "Accept-Language"
        $preferredLanguage = $request->getPreferredLanguage(['en', 'fr']); // Ajoute les langues supportées
        $locale = $preferredLanguage ?: $this->defaultLocale;


        // Appliquer la locale au service de traduction
        $this->translator->setLocale($lang);
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
