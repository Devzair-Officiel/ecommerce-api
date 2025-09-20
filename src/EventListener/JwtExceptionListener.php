<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;

class JwtExceptionListener
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * Gestion du cas où le token est manquant.
     */
    public function onJWTNotFound(JWTNotFoundEvent $event): void
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('token.missing'),
            'status' => Response::HTTP_UNAUTHORIZED,
            'errors' => [
                ['type' => 'missing token', 'message' => $this->translator->trans('token.missing')],
            ],
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }

    /**
     * Gestion du cas où le token est invalide.
     */
    public function onJWTInvalid(JWTInvalidEvent $event): void
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('token.invalid'),
            'status' => Response::HTTP_UNAUTHORIZED,
            'errors' => [
                ['type' => 'invalid token', 'message' => $this->translator->trans('token.invalid')],
            ],
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }

    /**
     * Gestion du cas où le token est expiré.
     */
    public function onJWTExpired(JWTExpiredEvent $event): void
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('token.expired'),
            'status' => Response::HTTP_UNAUTHORIZED,
            'errors' => [
                ['type' => 'expired token', 'message' => $this->translator->trans('token.expired')],
            ],
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }

    /**
     * Gestion des échecs d'authentification.
     */
    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        $response = new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans('token.failed'),
            'status' => Response::HTTP_UNAUTHORIZED,
            'errors' => [
                ['type' => 'authentication failed', 'message' => $this->translator->trans('token.failed')],
            ],
        ], Response::HTTP_UNAUTHORIZED);

        $event->setResponse($response);
    }
}
