<?php 

namespace App\DTO\User;

use Symfony\Component\Validator\Constraints as Assert;

class UserUpdatedDTO
{
    #[Assert\Email(message: 'user.email.invalid')]
    public ?string $email = null;

    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'user.firstname.min_length'
    )]
    public ?string $firstname = null;

    #[Assert\Length(
        min: 2,
        max: 50,
        minMessage: 'user.lastname.min_length'
    )]
    public ?string $lastname = null;
}