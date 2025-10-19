<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User\RefreshToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class RefreshTokenController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserProviderInterface $userProvider
    ) {}

    #[Route('/api/v1/token/refresh', name: 'refresh_token', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        // Récupère les données JSON depuis le body de la requête
        $data = json_decode($request->getContent(), true);
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            throw new BadRequestHttpException('Missing refresh_token in the request body.');
        }

        // Récupère l'objet RefreshToken depuis la base de données
        $token = $this->refreshTokenManager->get($refreshToken);
        if (!$token || !$token->isValid()) {
            return new JsonResponse(['error' => 'Invalid or expired refresh token.'], 400);
        }

        // Récupère l'utilisateur via le UserProvider
        $user = $this->userProvider->loadUserByIdentifier($token->getUsername());
        if (!$user) {
            return new JsonResponse(['error' => 'User not found.'], 404);
        }

        // Génère un nouveau token JWT pour l'utilisateur
        $newJwtToken = $this->jwtManager->create($user);

        // Supprime l'ancien refresh token
        $this->refreshTokenManager->delete($token);

        // Crée un nouveau refresh token
        $newRefreshToken = new RefreshToken();
        $newRefreshToken->setRefreshToken(bin2hex(random_bytes(32))); // Génère un token unique
        $newRefreshToken->setUsername($user->getUserIdentifier()); // Utilisez l'identifiant de l'utilisateur
        $newRefreshToken->setValid((new \DateTime())->modify('+7 days')); // Valide pendant 7 jours

        $this->refreshTokenManager->save($newRefreshToken);

        return new JsonResponse([
            'token' => $newJwtToken,
            'refresh_token' => $newRefreshToken->getRefreshToken(),
        ]);
    }
}
