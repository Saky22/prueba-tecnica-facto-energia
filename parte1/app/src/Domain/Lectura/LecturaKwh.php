<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use InvalidArgumentException;

/**
 * Valor acumulado del contador en kWh (entero no negativo).
 */
final class LecturaKwh
{
    private function __construct(private readonly int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                sprintf('El valor de lectura en kWh debe ser un entero no negativo. Recibido: %d.', $value)
            );
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
