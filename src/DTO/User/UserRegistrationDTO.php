<?php

namespace App\DTO\User;

use Symfony\Component\Validator\Constraints as Assert;

class UserRegistrationDTO
{
    #[Assert\NotBlank(message: 'user.email.not_blank')]
    #[Assert\Email(message: 'user.email.invalid')]
    public string $email;

    #[Assert\NotBlank(message: 'user.password.not_blank')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'user.password.min_length'
    )]
    public string $password;

    #[Assert\NotBlank(message: 'user.firstname.not_blank')]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'user.firstname.min_length'
    )]
    public string $firstname;

    #[Assert\NotBlank(message: 'user.lastname.not_blank')]
    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'user.lastname.min_length'
    )]
    public string $lastname;
}
