<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use DateTimeImmutable;

/**
 * Domain Entity representing a supply contract.
 * Encapsulates business rules about vigency and acceptance of readings.
 */
final class Contrato
{
    public function __construct(
        private readonly int $id,
        private readonly Cups $cups,
        private readonly string $estado,
        private readonly DateTimeImmutable $fechaAlta,
        private readonly ?DateTimeImmutable $fechaBaja,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function cups(): Cups
    {
        return $this->cups;
    }

    /**
     * Returns true if this contract was active (vigente) on the given date.
     * Note: a contract with estado='baja' can still be vigente on a past date.
     * Vigency is determined by fecha_alta ≤ date ≤ fecha_baja (or fecha_baja IS NULL).
     */
    public function estaVigenteEn(DateTimeImmutable $fecha): bool
    {
        if ($fecha < $this->fechaAlta) {
            return false;
        }

        if ($this->fechaBaja !== null && $fecha > $this->fechaBaja) {
            return false;
        }

        return true;
    }

    /**
     * Returns true if the contract was in baja status on the given date.
     * A contract is in baja on a date if its estado is 'baja' AND the date
     * falls within its vigency period.
     */
    public function estaDeBajaEn(DateTimeImmutable $fecha): bool
    {
        return $this->estado === 'baja' && $this->estaVigenteEn($fecha);
    }
}
