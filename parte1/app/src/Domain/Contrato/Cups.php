<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use InvalidArgumentException;

/**
 * Value Object that represents a CUPS (Código Universal de Punto de Suministro).
 * Invariant: must start with "ES" and be 20–22 characters long.
 */
final class Cups
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper(trim($value));

        if (!preg_match('/^ES[A-Z0-9]{18,20}$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('El CUPS "%s" no tiene un formato válido (debe comenzar por ES y tener entre 20 y 22 caracteres).', $value)
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
