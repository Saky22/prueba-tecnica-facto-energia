<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use InvalidArgumentException;

/**
 * Value Object for the reading type.
 * Invariant: must be either 'real' or 'estimada'.
 */
final class TipoLectura
{
    private const ALLOWED = ['real', 'estimada'];

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (!in_array($value, self::ALLOWED, true)) {
            throw new InvalidArgumentException(
                sprintf('El tipo de lectura debe ser "real" o "estimada". Recibido: "%s".', $value)
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
