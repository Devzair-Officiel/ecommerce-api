<?php

declare(strict_types=1);

namespace App\Enum\Cart;

enum CartStatus: string
{
    case ACTIVE = 'ACTIVE';
    case LOCKED = 'LOCKED'; // optionnel, utilisé pendant le paiement
    case ARCHIVED = 'ARCHIVED'; // ex. après conversion en Order
}
