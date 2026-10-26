<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use InvalidArgumentException;

/**
 * CUPS: empieza por ES y tiene entre 20 y 22 caracteres.
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
