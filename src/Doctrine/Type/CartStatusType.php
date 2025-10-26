<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Enum\Cart\CartStatus;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

final class CartStatusType extends Type
{
    public const NAME = 'cart_status';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return 'VARCHAR(20)';
    }
    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof CartStatus ? $value->value : ($value ?? null);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CartStatus
    {
        return $value ? CartStatus::from($value) : null;
    }
}
