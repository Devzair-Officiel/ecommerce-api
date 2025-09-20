<?php 

namespace App\DTO\User;

use Symfony\Component\Serializer\Annotation\Groups;


class UserResponseDTO
{
    public function __construct(
        #[Groups(['user_list', 'user_detail'])]
        public readonly int $id,

        #[Groups(['user_list', 'user_detail'])]
        public readonly string $email,

        #[Groups(['user_list', 'user_detail'])]
        public readonly string $firstname,

        #[Groups(['user_list', 'user_detail'])]
        public readonly string $lastname,

        #[Groups(['user_list', 'user_detail'])]
        public readonly string $lastLogin,

        #[Groups(['user_detail'])]
        public readonly array $teams,

        #[Groups(['user_detail'])]
        public readonly bool $isValid,

        #[Groups(['user_detail'])]
        public readonly string $createdAt,

        #[Groups(['user_detail'])]
        public readonly ?string $updatedAt,

        #[Groups(['user_detail'])]
        public readonly ?string $closedAt,
    ) {}
    
}