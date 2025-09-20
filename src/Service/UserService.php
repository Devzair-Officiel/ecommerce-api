<?php

namespace App\Service;

use App\Entity\User;
use App\Utils\PaginationUtils;
use App\DTO\User\UserUpdatedDTO;
use App\Repository\UserRepository;
use App\Utils\JsonValidationUtils;
use App\DTO\User\UserRegistrationDTO;
use App\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Exception\EntityNotFoundException;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class UserService extends AbstractService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected JsonValidationUtils $jsonValidationUtils,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenManagerInterface $refreshTokenManager,
    ) {}

    /**
     * Récupère une liste d'utilisateurs.
     *
     * @param int $page  Le numéro de la page actuelle.
     * @param int $limit Le nombre d'éléments par page.
     */
    public function getAllUsers(int $page, int $limit, array $filters): array
    {
        // Nombre total d'utilisateurs
        $totalUsers = $this->userRepository->count([]);

        // Instancier l'objet de pagination
        $pagination = new PaginationUtils($page, $limit, $totalUsers);

        // Valider la page
        $pagination->validatePage();

        $offset = $pagination->getOffset();
        $limit = $pagination->getLimit();

        $users = $this->userRepository->findUsersWithPaginationAndFilters($offset, $limit, $filters);


        return [
            'pagination' => new PaginationUtils($page, $limit, $users['totalItemsFound']),
            'items' => $users['items'],
            'totalItemsFound' => $users['totalItemsFound'],
        ];
    }

    /**
     * Récuperation d'un utilisateur
     * 
     * @param int $id
     * @return User
     */
    public function getUserById(int $id): ?User
    {
        return $this->findEntityById(User::class, $id);
    }

    /**
     * Création User
     * 
     * @param \App\DTO\User\UserRegistrationDTO $userDTO
     * @return \App\Entity\User
     */
    public function registerUser(UserRegistrationDTO $userDTO, array $jsonData): array
    {
        // Valider les clés JSON
        $invalidKeys = $this->jsonValidationUtils->validateKeys($jsonData, User::class);

        if (!empty($invalidKeys)) {
            // Lever une exception si des clés JSON sont invalides
            throw new \InvalidArgumentException(
                implode(', ', $invalidKeys)
            );
        }

        // Vérification de l'unicité de l'email
        if ($userDTO->email !== null && $this->emailExists($userDTO->email)) {
            throw new ValidationException(
                errors: [
                    [
                        'field' => 'email',
                        'message' => 'validation.email_taken'
                    ]
                ],
                messageKey: 'validation.failed',
                translationParameters: ['%field%' => 'email']
            );
        }

        // Créer l'utilisateur
        $user = new User();
        $user->setEmail($userDTO->email);
        $user->setFirstname($userDTO->firstname);
        $user->setLastname($userDTO->lastname);
        $user->setValid(true);

        // Hashage du mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $userDTO->password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();
        
        // Génération du JWT Token
        $jwtToken = $this->jwtManager->create($user);

        // Création d'un Refresh Token
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(bin2hex(random_bytes(32))); // Génère un token unique
        $refreshToken->setUsername($user->getUserIdentifier()); // Identifiant de l'utilisateur
        $refreshToken->setValid((new \DateTime())->modify('+' . 3600 . ' seconds')); // Durée de vie

        $this->refreshTokenManager->save($refreshToken);

        return [
            'user' => $user,
            'token' => $jwtToken,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ];

    }

    /**
     * Mise à jour d'un utilisateur
     * 
     * @param \App\DTO\User\UserUpdatedDTO $userDTO
     * @param \App\Entity\User $user
     * @return \App\Entity\User
     */
    public function updatedUser(int $id, UserUpdatedDTO $userDTO, array $jsonData): User
    {
        // Valider les clés JSON
        $invalidKeys = $this->jsonValidationUtils->validateKeys($jsonData, User::class);

        if (!empty($invalidKeys)) {
            // Lever une exception si des clés JSON sont invalides
            throw new \InvalidArgumentException(
                implode(', ', $invalidKeys)
            );
        }

        $user = $this->findEntityById(User::class, $id);

        if (!$user) {
            throw new EntityNotFoundException('user');
        }

        // Vérification de l'unicité de l'email si l'email est modifié
        if ($userDTO->email !== null && $userDTO->email !== $user->getEmail()) {
            if ($this->emailExists($userDTO->email)) {
                throw new ValidationException(
                    errors: [
                        [
                            'field' => 'email',
                            'message' => 'validation.email_taken'
                        ]
                    ],
                    messageKey: 'validation.failed',
                    translationParameters: ['%field%' => 'email']
                );
            }
        }

        if ($userDTO->email !== null) {
            $user->setEmail($userDTO->email);
        }


        if ($userDTO->firstname !== null) {
            $user->setFirstname($userDTO->firstname);
        }

        if ($userDTO->lastname !== null) {
            $user->setLastname($userDTO->lastname);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $user;
    }

    /**
     * Désactive un utilisateur.
     *
     * @param User $user
     * @return User
     */
    public function deactivateUser(int $id): void
    {
        $user = $this->findEntityById(User::class, $id);

        $this->disableEntity($user);
    }

    /**
     * Activer un utilisateur.
     *
     * @param User $user
     * @return User
     */
    public function activateUser(int $id): void
    {
        $user = $this->findEntityById(User::class, $id);
        $this->enableEntity($user);
    }

    /**
     * Vérifie si le mail existe en BDD
     * 
     * @param string $email
     * @return bool
     */
    public function emailExists(string $email): bool
    {
        return $this->userRepository->findOneBy(['email' => $email]) !== null;
    }

    public function generateTokens(UserInterface $user, int $ttl = 604800): array
    {
        // Génération du JWT Token
        $jwtToken = $this->jwtManager->create($user);

        // Création d'un Refresh Token
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(bin2hex(random_bytes(32))); // Génère un token unique
        $refreshToken->setUsername($user->getUserIdentifier()); // Identifiant de l'utilisateur
        $refreshToken->setValid((new \DateTime())->modify('+' . $ttl . ' seconds')); // Durée de vie

        $this->refreshTokenManager->save($refreshToken);

        return [
            'token' => $jwtToken,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ];
    }
}
