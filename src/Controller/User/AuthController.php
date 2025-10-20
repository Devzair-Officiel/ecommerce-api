<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Core\AbstractApiController;
use App\Entity\Site\Site;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Repository\Site\SiteRepository;
use App\Service\User\UserService;
use App\Utils\ApiResponseUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use App\Entity\User\User;

/**
 * Contrôleur d'authentification (endpoints publics).
 * 
 * Endpoints :
 * - POST /register : Inscription avec génération de tokens JWT
 * - POST /login_check : Connexion (géré par Lexik JWT - configuration uniquement)
 * - POST /verify-email : Vérification de l'email
 * - POST /resend-verification : Renvoyer l'email de vérification
 * - POST /forgot-password : Demande de réinitialisation
 * - POST /reset-password : Réinitialisation du mot de passe
 * - GET  /me : Informations de l'utilisateur connecté
 */
#[Route('/auth', name: 'api_auth_')]
class AuthController extends AbstractApiController
{
    public function __construct(
        ApiResponseUtils $apiResponseUtils,
        SerializerInterface $serializer,
        private readonly UserService $userService,
        private readonly SiteRepository $siteRepository
    ) {
        parent::__construct($apiResponseUtils, $serializer);
    }

    // ===============================================
    // INSCRIPTION
    // ===============================================

    /**
     * Inscription d'un nouvel utilisateur.
     * 
     * Retourne :
     * - Les données utilisateur
     * - Le token JWT
     * - Le refresh token
     * 
     * Body attendu :
     * {
     *   "email": "user@example.com",
     *   "plainPassword": "Password123!",
     *   "firstName": "John",
     *   "lastName": "Doe",
     *   "phone": "+33612345678",
     *   "newsletterOptIn": false
     * }
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // Validation des champs requis
            $this->requireFields($data, ['email', 'plainPassword'], 'Email et mot de passe requis');

            // Récupérer le site (multi-tenant)
            $site = $this->getSiteFromRequest($request);

            // Créer l'utilisateur avec tokens JWT
            $result = $this->userService->createUserWithTokens($data, $site);

            // Sérialiser l'utilisateur
            $userData = $this->serialize($result['user'], ['user:read', 'date']);

            // Ajouter les tokens à la réponse
            $userData['token'] = $result['token'];
            $userData['refresh_token'] = $result['refresh_token'];

            // TODO: Envoyer l'email de vérification
            // $this->emailService->sendVerificationEmail($result['user']);

            return $this->apiResponseUtils->created(
                data: $userData,
                entityKey: 'user'
            );
        } catch (ConflictException $e) {
            return $this->apiResponseUtils->error(
                errors: [['field' => 'email', 'message' => 'Cet email est déjà utilisé.']],
                messageKey: 'user.email_taken',
                status: 409
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError($e->getMessage());
        }
    }

    // ===============================================
    // CONNEXION (géré par Lexik JWT)
    // ===============================================

    /**
     * Endpoint de connexion.
     * 
     * ⚠️ Cet endpoint est géré automatiquement par Lexik JWT Bundle.
     * Configuration dans config/packages/security.yaml
     * 
     * Body attendu :
     * {
     *   "email": "user@example.com",
     *   "password": "Password123!"
     * }
     * 
     * Réponse :
     * {
     *   "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
     *   "refresh_token": "..."
     * }
     */
    // Route définie dans routes.yaml : POST /api/v1/login_check

    // ===============================================
    // VÉRIFICATION EMAIL
    // ===============================================

    /**
     * Vérifie l'email d'un utilisateur via le token.
     * 
     * Body attendu :
     * {
     *   "token": "verification_token_here"
     * }
     */
    #[Route('/verify-email', name: 'verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'token', 'Token de vérification requis');

            $user = $this->userService->verifyEmail($data['token']);

            return $this->apiResponseUtils->success(
                data: $this->serialize($user, ['user:read', 'date']),
                messageKey: 'user.email_verified',
                entityKey: 'user'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'auth.invalid_token',
                status: 400
            );
        } catch (\LogicException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => $e->getMessage()]],
                messageKey: 'auth.already_verified',
                status: 400
            );
        }
    }

    /**
     * Renvoie l'email de vérification.
     * 
     * Body attendu :
     * {
     *   "email": "user@example.com"
     * }
     */
    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'email', 'Email requis');

            $site = $this->getSiteFromRequest($request);
            $user = $this->userService->findByEmailAndSite($data['email'], $site);

            if (!$user) {
                // Ne pas révéler si l'email existe ou non (sécurité)
                return $this->apiResponseUtils->success(
                    messageKey: 'user.verification_email_sent'
                );
            }

            if ($user->isVerified()) {
                return $this->apiResponseUtils->error(
                    errors: [['message' => 'Ce compte est déjà vérifié.']],
                    messageKey: 'auth.already_verified',
                    status: 400
                );
            }

            $this->userService->regenerateVerificationToken($user);

            // TODO: Envoyer l'email de vérification
            // $this->emailService->sendVerificationEmail($user);

            return $this->apiResponseUtils->success(
                messageKey: 'user.verification_email_sent'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('email');
        }
    }

    // ===============================================
    // MOT DE PASSE OUBLIÉ
    // ===============================================

    /**
     * Demande de réinitialisation du mot de passe.
     * 
     * Body attendu :
     * {
     *   "email": "user@example.com"
     * }
     */
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireField($data, 'email', 'Email requis');

            $site = $this->getSiteFromRequest($request);
            $user = $this->userService->findByEmailAndSite($data['email'], $site);

            if (!$user) {
                // Ne pas révéler si l'email existe ou non (sécurité)
                return $this->apiResponseUtils->success(
                    messageKey: 'auth.reset_email_sent'
                );
            }

            // TODO: Générer un token de réinitialisation
            // TODO: Envoyer l'email avec le lien de réinitialisation
            // $resetToken = $this->userService->generatePasswordResetToken($user);
            // $this->emailService->sendPasswordResetEmail($user, $resetToken);

            return $this->apiResponseUtils->success(
                messageKey: 'auth.reset_email_sent'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError('email');
        }
    }

    /**
     * Réinitialisation du mot de passe via token.
     * 
     * Body attendu :
     * {
     *   "token": "reset_token_here",
     *   "newPassword": "NewPassword123!"
     * }
     */
    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireFields($data, ['token', 'newPassword'], 'Token et nouveau mot de passe requis');

            // TODO: Implémenter la logique de réinitialisation
            // $user = $this->userService->resetPasswordWithToken($data['token'], $data['newPassword']);

            return $this->apiResponseUtils->success(
                messageKey: 'auth.password_reset_success'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseUtils->error(
                errors: [['message' => 'Token invalide ou expiré.']],
                messageKey: 'auth.invalid_reset_token',
                status: 400
            );
        }
    }

    // ===============================================
    // UTILISATEUR CONNECTÉ
    // ===============================================

    /**
     * Récupère les informations de l'utilisateur connecté.
     * 
     * Headers requis :
     * Authorization: Bearer {token}
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->apiResponseUtils->success(
            data: $this->serialize($user, ['user:read', 'date', 'site']),
            messageKey: 'user.profile_retrieved',
            entityKey: 'user'
        );
    }

    /**
     * Met à jour le profil de l'utilisateur connecté.
     * 
     * Body attendu (tous les champs sont optionnels) :
     * {
     *   "firstName": "John",
     *   "lastName": "Doe",
     *   "phone": "+33612345678",
     *   "birthDate": "1990-01-15",
     *   "newsletterOptIn": true
     * }
     */
    #[Route('/me', name: 'update_me', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function updateMe(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            // Sécurité : empêcher la modification de champs sensibles
            unset($data['email'], $data['password'], $data['plainPassword'], $data['roles'], $data['isVerified']);

            $updatedUser = $this->userService->update($user->getId(), $data);

            return $this->apiResponseUtils->success(
                data: $this->serialize($updatedUser, ['user:read', 'date']),
                messageKey: 'user.profile_updated',
                entityKey: 'user'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        }
    }

    /**
     * Change le mot de passe de l'utilisateur connecté.
     * 
     * Body attendu :
     * {
     *   "currentPassword": "OldPassword123!",
     *   "newPassword": "NewPassword123!"
     * }
     */
    #[Route('/me/change-password', name: 'change_my_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function changeMyPassword(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->getJsonData($request);

        try {
            $this->requireFields($data, ['currentPassword', 'newPassword'], 'Mots de passe requis');

            // Vérifier l'ancien mot de passe
            if (!$this->userService->isPasswordValid($user, $data['currentPassword'])) {
                return $this->apiResponseUtils->error(
                    errors: [['field' => 'currentPassword', 'message' => 'Mot de passe actuel incorrect.']],
                    messageKey: 'user.invalid_current_password',
                    status: 400
                );
            }

            // Changer le mot de passe
            $this->userService->changePassword($user->getId(), $data['newPassword']);

            return $this->apiResponseUtils->success(
                messageKey: 'user.password_changed'
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->getFormattedErrors());
        } catch (\InvalidArgumentException $e) {
            return $this->missingFieldError($e->getMessage());
        }
    }

    // ===============================================
    // HELPER
    // ===============================================

    /**
     * Récupère le site depuis la requête (multi-tenant).
     * 
     * TODO: Implémenter la détection automatique du site selon votre stratégie :
     * - Depuis le domaine (Header Host)
     * - Depuis un header X-Site-Code
     * - Depuis un paramètre dans le body
     */
    private function getSiteFromRequest(Request $request): Site
    {
        // Option 1 : Depuis le domaine (recommandé en production)
        // $domain = $request->getHost();
        // $site = $this->siteRepository->findByDomain($domain);

        // Option 2 : Depuis un header custom
        // $siteCode = $request->headers->get('X-Site-Code');
        // $site = $this->siteRepository->findByCode($siteCode);

        // Option 3 : Depuis le body (temporaire pour développement)
        $data = $this->getJsonData($request);
        if (isset($data['siteCode'])) {
            $site = $this->siteRepository->findByCode($data['siteCode']);
            if ($site) {
                return $site;
            }
        }

        // Fallback : Retourner le premier site (pour développement uniquement)
        $sites = $this->siteRepository->findAccessibleSites();
        if (empty($sites)) {
            throw new \RuntimeException('Aucun site disponible. Veuillez créer un site avant de vous inscrire.');
        }

        return $sites[0]; // Retourne le premier site actif
    }
}
